<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private CreditService $credit,
        private InventoryService $inventory,
        private InvoiceService $invoices,
        private TargetService $targets,
    ) {}

    /**
     * @param  list<array{product_id:int, quantity:int, unit_price?:float}>  $lines
     */
    public function create(array $data, array $lines, ?User $actor = null): ProductReturn
    {
        $lines = collect($lines)->filter(fn ($l) => (int) ($l['quantity'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one return line.']);
        }

        if (empty($data['order_id'])) {
            throw ValidationException::withMessages(['order_id' => 'Pick the delivered order these goods came from.']);
        }

        return DB::transaction(function () use ($data, $lines, $actor) {
            $order = Order::query()->lockForUpdate()->with('items')->find($data['order_id']);
            if (! $order || (int) $order->shop_id !== (int) $data['shop_id']) {
                throw ValidationException::withMessages(['order_id' => 'That order does not belong to this shop.']);
            }
            if ($order->status !== Order::STATUS_DELIVERED) {
                throw ValidationException::withMessages(['order_id' => "Order {$order->number} is not delivered yet, so nothing can be returned."]);
            }

            $returnable = $this->returnableLines($order);
            $lines = $lines->groupBy(fn ($l) => (int) $l['product_id'])->map(fn ($group, $productId) => [
                'product_id' => $productId,
                'quantity' => (int) $group->sum(fn ($l) => (int) $l['quantity']),
            ]);
            foreach ($lines as $productId => $line) {
                $left = $returnable[$productId]['left'] ?? 0;
                if ($line['quantity'] > $left) {
                    $sku = $returnable[$productId]['sku'] ?? Product::query()->whereKey($productId)->value('sku') ?? "#{$productId}";
                    throw ValidationException::withMessages([
                        'items' => $left > 0
                            ? "{$sku}: only {$left} can still be returned from {$order->number}."
                            : "{$sku} was not sold on {$order->number} or is already fully returned.",
                    ]);
                }
            }

            $return = ProductReturn::query()->create([
                'number' => $this->nextNumber(),
                'shop_id' => $order->shop_id,
                'order_id' => $order->id,
                'delivery_id' => $data['delivery_id'] ?? null,
                'invoice_id' => $order->invoice_id,
                'status' => ProductReturn::STATUS_PENDING,
                'reason_type' => $data['reason_type'] ?? ProductReturn::REASON_WARRANTY,
                'reason' => $data['reason'] ?? null,
                'restock' => $data['restock'] ?? true,
                'created_by' => $actor?->id,
            ]);

            $total = 0;
            foreach ($lines as $productId => $line) {
                $product = Product::query()->findOrFail($productId);
                $qty = (int) $line['quantity'];
                $unit = (float) $returnable[$productId]['unit_price'];
                $lineTotal = round($unit * $qty, 2);

                ReturnItem::query()->create([
                    'return_id' => $return->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);

                $total += $lineTotal;
            }

            $return->update(['total' => round($total, 2)]);

            $this->auditLogger->log(
                'returns',
                'created',
                "Return {$return->number} submitted",
                $return,
                null,
                ['total' => $total, 'reason_type' => $return->reason_type],
                $actor,
            );

            $fresh = $return->fresh(['items.product', 'shop', 'order', 'creator']);

            DB::afterCommit(function () use ($fresh) {
                try {
                    app(AppNotificationService::class)->returnSubmitted($fresh);
                } catch (\Throwable) {
                    // non-blocking
                }
            });

            return $fresh;
        });
    }

    /**
     * What is still returnable per product on a delivered order: sold minus pending/approved/restocked returns,
     * priced at the average unit price actually charged on the order.
     *
     * @return array<int, array{sku:string, sold:int, left:int, unit_price:float}>
     */
    public function returnableLines(Order $order): array
    {
        $order->loadMissing('items');
        $alreadyReturned = ReturnItem::query()
            ->whereHas('productReturn', fn ($q) => $q->where('order_id', $order->id)->where('status', '!=', ProductReturn::STATUS_REJECTED))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $out = [];
        foreach ($order->items->where('product_id', '!=', null)->groupBy('product_id') as $productId => $items) {
            $sold = (int) $items->sum('quantity');
            $value = (float) $items->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity);
            $out[(int) $productId] = [
                'sku' => (string) $items->first()->product_sku,
                'sold' => $sold,
                'left' => max(0, $sold - (int) ($alreadyReturned[$productId] ?? 0)),
                'unit_price' => $sold > 0 ? round($value / $sold, 2) : 0.0,
            ];
        }

        return $out;
    }

    public function createFromOrder(Order $order, array $quantities, array $meta, ?User $actor = null): ProductReturn
    {
        $order->load('items');
        $lines = [];
        foreach ($order->items as $item) {
            $qty = (int) ($quantities[$item->product_id] ?? $quantities[$item->id] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => $item->product_id,
                'quantity' => $qty,
            ];
        }

        return $this->create([
            'shop_id' => $order->shop_id,
            'order_id' => $order->id,
            'delivery_id' => $meta['delivery_id'] ?? null,
            'invoice_id' => $order->invoice_id,
            'reason_type' => $meta['reason_type'] ?? ProductReturn::REASON_WARRANTY,
            'reason' => $meta['reason'] ?? null,
            'restock' => $meta['restock'] ?? true,
        ], $lines, $actor);
    }

    /**
     * After a salesman records shop-accepted quantities, unsold units become a pending return
     * (no inventory change until admin/manager approves and restocks).
     */
    public function createPendingFromDeliveryShortfall(Delivery $delivery, User $actor): ?ProductReturn
    {
        $delivery->loadMissing(['items', 'order.items', 'shop']);
        $order = $delivery->order;
        if (! $order || $order->status !== Order::STATUS_DELIVERED) {
            return null;
        }

        $quantities = [];
        foreach ($delivery->items as $item) {
            $left = max(0, (int) $item->qty_received - (int) $item->qty_delivered - (int) $item->qty_returned);
            if ($left <= 0 || ! $item->product_id) {
                continue;
            }
            $quantities[(int) $item->product_id] = ($quantities[(int) $item->product_id] ?? 0) + $left;
        }

        if ($quantities === []) {
            return null;
        }

        return DB::transaction(function () use ($delivery, $order, $quantities, $actor) {
            $return = $this->createFromOrder($order->fresh('items'), $quantities, [
                'delivery_id' => $delivery->id,
                'reason_type' => ProductReturn::REASON_NOT_ACCEPTED,
                'reason' => 'Shop accepted fewer units on delivery '.$delivery->number.'. Unsold stock held for return verification — not added to inventory yet.',
                'restock' => true,
            ], $actor);

            // Mark units as committed to the return queue so they leave salesman stock,
            // without booking inventory until restock approval.
            foreach ($delivery->items as $item) {
                $left = max(0, (int) $item->qty_received - (int) $item->qty_delivered - (int) $item->qty_returned);
                if ($left <= 0) {
                    continue;
                }
                $item->update(['qty_returned' => (int) $item->qty_returned + $left]);
            }

            return $return->fresh(['items', 'shop', 'order', 'creator']);
        });
    }

    /**
     * Verify a pending return and issue shop credit. Does NOT touch main inventory.
     */
    public function approve(ProductReturn $return, ?User $actor = null, ?string $notes = null, bool $restockNow = false): ProductReturn
    {
        if ($return->status !== ProductReturn::STATUS_PENDING) {
            throw ValidationException::withMessages(['return' => 'Only pending returns can be approved.']);
        }

        $approved = DB::transaction(function () use ($return, $actor, $notes) {
            $return = ProductReturn::query()->lockForUpdate()->with(['items.product', 'shop', 'order'])->findOrFail($return->id);
            if ($return->status !== ProductReturn::STATUS_PENDING) {
                throw ValidationException::withMessages(['return' => 'Only pending returns can be approved.']);
            }

            $invoiceId = $return->invoice_id ?: $return->order?->invoice_id;
            $applied = $invoiceId && ($invoice = Invoice::query()->find($invoiceId))
                ? $this->invoices->applyCredit($invoice, (float) $return->total)
                : 0.0;

            $return->update([
                'status' => ProductReturn::STATUS_APPROVED,
                'applied_to_invoice' => $applied,
                'credit_issued' => true,
                'approved_at' => now(),
                'approved_by' => $actor?->id,
                'resolution_notes' => $notes,
            ]);

            $this->credit->recalculateOutstanding($return->shop);
            $this->targets->recalculateFor($return->order?->salesman_id);

            $this->auditLogger->log(
                'returns',
                'approved',
                "Return {$return->number} approved — credit issued (awaiting inventory restock)",
                $return,
                null,
                ['total' => (float) $return->total, 'applied_to_invoice' => $applied],
                $actor,
            );

            return $return->fresh(['items', 'shop', 'approver', 'order']);
        });

        if ($restockNow && $approved->restock) {
            return $this->addToInventory($approved, $actor, $notes);
        }

        return $approved;
    }

    /**
     * Add approved return quantities back into main warehouse inventory.
     */
    public function addToInventory(ProductReturn $return, ?User $actor = null, ?string $notes = null): ProductReturn
    {
        if (! in_array($return->status, [ProductReturn::STATUS_APPROVED, ProductReturn::STATUS_PENDING], true)) {
            throw ValidationException::withMessages(['return' => 'Only approved (or pending with restock-now) returns can be added to inventory.']);
        }

        return DB::transaction(function () use ($return, $actor, $notes) {
            $return = ProductReturn::query()->lockForUpdate()->with(['items.product', 'shop'])->findOrFail($return->id);

            if ($return->status === ProductReturn::STATUS_RESTOCKED) {
                return $return->fresh(['items', 'shop', 'approver', 'restocker']);
            }

            if ($return->status === ProductReturn::STATUS_PENDING) {
                throw ValidationException::withMessages(['return' => 'Approve the return before adding stock to inventory.']);
            }

            if ($return->status !== ProductReturn::STATUS_APPROVED) {
                throw ValidationException::withMessages(['return' => 'This return cannot be restocked.']);
            }

            $warehouse = Warehouse::defaultWarehouse();
            if (! $warehouse) {
                throw ValidationException::withMessages(['return' => 'No default warehouse is configured for restocking.']);
            }

            foreach ($return->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $this->inventory->receive(
                    $warehouse,
                    $item->product,
                    (int) $item->quantity,
                    $actor,
                    "Return {$return->number} restock",
                    $return->number,
                );
            }

            $return->update([
                'status' => ProductReturn::STATUS_RESTOCKED,
                'restock' => true,
                'restocked_at' => now(),
                'restocked_by' => $actor?->id,
                'resolution_notes' => $notes ?: $return->resolution_notes,
            ]);

            $this->auditLogger->log(
                'returns',
                'restocked',
                "Return {$return->number} added to inventory",
                $return,
                null,
                ['total_qty' => (int) $return->items->sum('quantity')],
                $actor,
            );

            return $return->fresh(['items', 'shop', 'approver', 'restocker']);
        });
    }

    public function reject(ProductReturn $return, string $reason, ?User $actor = null): ProductReturn
    {
        if ($return->status !== ProductReturn::STATUS_PENDING) {
            throw ValidationException::withMessages(['return' => 'Only pending returns can be rejected.']);
        }

        return DB::transaction(function () use ($return, $reason, $actor) {
            $return = ProductReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ProductReturn::STATUS_PENDING) {
                throw ValidationException::withMessages(['return' => 'Only pending returns can be rejected.']);
            }

            $return->update([
                'status' => ProductReturn::STATUS_REJECTED,
                'resolution_notes' => $reason,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            $this->auditLogger->log('returns', 'rejected', "Return {$return->number} rejected", $return, null, ['reason' => $reason], $actor);

            return $return->fresh();
        });
    }

    public function nextNumber(): string
    {
        return \App\Support\DocumentNumber::next(ProductReturn::class, 'RET-'.now()->format('ymd').'-', 4);
    }
}
