<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryStockService
{
    public function __construct(private InventoryService $inventory) {}

    public function seedItems(Delivery $delivery): void
    {
        $delivery->loadMissing('order.items.product');

        if ($delivery->items()->exists()) {
            return;
        }

        foreach ($delivery->order?->items ?? [] as $item) {
            /** @var OrderItem $item */
            DeliveryItem::query()->create([
                'delivery_id' => $delivery->id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'qty_ordered' => (int) $item->quantity,
                'qty_received' => 0,
                'qty_delivered' => 0,
                'qty_returned' => 0,
            ]);
        }
    }

    /**
     * Salesman confirms how many units they took from the warehouse for this delivery.
     *
     * @param  array<int, array{id:int, qty:int}>  $lines
     */
    public function receive(Delivery $delivery, array $lines, User $actor): Delivery
    {
        if ($delivery->status === Delivery::STATUS_DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'This delivery is already completed.']);
        }

        return DB::transaction(function () use ($delivery, $lines, $actor) {
            $delivery = Delivery::query()->lockForUpdate()->with(['items.product', 'warehouse', 'order'])->findOrFail($delivery->id);
            $this->seedItems($delivery);
            $delivery->load('items.product');

            $byId = collect($lines)->keyBy(fn ($row) => (int) $row['id']);

            foreach ($delivery->items as $item) {
                if ((int) $item->qty_received > 0) {
                    continue; // already confirmed
                }

                $qty = (int) ($byId[$item->id]['qty'] ?? $item->qty_ordered);
                if ($qty < 0) {
                    throw ValidationException::withMessages(['qty' => 'Received quantity cannot be negative.']);
                }
                if ($qty > (int) $item->qty_ordered) {
                    throw ValidationException::withMessages([
                        'qty' => "{$item->product_name}: cannot receive more than ordered ({$item->qty_ordered}).",
                    ]);
                }

                // Pick already removed ordered qty from warehouse. Units not loaded
                // onto the salesman go straight back into warehouse stock.
                $notLoaded = (int) $item->qty_ordered - $qty;
                if ($notLoaded > 0 && $item->product && $delivery->warehouse) {
                    $this->inventory->receive(
                        $delivery->warehouse,
                        $item->product,
                        $notLoaded,
                        $actor,
                        "Not loaded onto {$delivery->number}",
                        $delivery->number,
                    );
                }

                $item->update([
                    'qty_received' => $qty,
                    'received_at' => $qty > 0 ? now() : null,
                ]);
            }

            $delivery->update([
                'received_at' => now(),
                'received_by' => $actor->id,
            ]);

            return $delivery->fresh(['items.product', 'assignee', 'shop', 'order']);
        });
    }

    /**
     * Salesman records how many units reached the shop. Remaining stay with the salesman until returned.
     *
     * @param  array<int, array{id:int, qty:int}>  $lines
     */
    public function recordDeliveredQuantities(Delivery $delivery, array $lines, User $actor, bool $complete = true): Delivery
    {
        if ($delivery->status === Delivery::STATUS_DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'Already delivered.']);
        }

        return DB::transaction(function () use ($delivery, $lines, $actor, $complete) {
            $delivery = Delivery::query()->lockForUpdate()->with(['items.product', 'warehouse'])->findOrFail($delivery->id);
            $this->seedItems($delivery);
            $delivery->load('items.product');

            if (! $delivery->isFullyReceived()) {
                // Auto-receive full ordered qty the first time they deliver, if they skipped receive.
                $this->receive(
                    $delivery,
                    $delivery->items->map(fn (DeliveryItem $item) => [
                        'id' => $item->id,
                        'qty' => (int) $item->qty_ordered,
                    ])->all(),
                    $actor,
                );
                $delivery = $delivery->fresh(['items.product', 'warehouse']);
            }

            $byId = collect($lines)->keyBy(fn ($row) => (int) $row['id']);

            foreach ($delivery->items as $item) {
                $qty = (int) ($byId[$item->id]['qty'] ?? $item->qty_received);
                if ($qty < 0) {
                    throw ValidationException::withMessages(['qty' => 'Delivered quantity cannot be negative.']);
                }
                $max = (int) $item->qty_received - (int) $item->qty_returned;
                if ($qty > $max) {
                    throw ValidationException::withMessages([
                        'qty' => "{$item->product_name}: can deliver at most {$max} (received minus returned).",
                    ]);
                }

                $item->update([
                    'qty_delivered' => $qty,
                    'delivered_at' => $qty > 0 ? now() : $item->delivered_at,
                ]);
            }

            // Inventory left the warehouse at pick time for ordered qty.
            // Delivered qty stays out. Remaining with salesman is tracked on the line.
            // Optional auto-return of leftovers when completing the delivery:
            if ($complete) {
                foreach ($delivery->fresh('items.product')->items as $item) {
                    $left = $item->qtyRemaining();
                    if ($left <= 0 || ! $item->product || ! $delivery->warehouse) {
                        continue;
                    }
                    // Keep leftovers with the salesman (do not auto-restock) — requirement #5.
                }
            }

            return $delivery->fresh(['items.product', 'assignee', 'shop', 'order']);
        });
    }

    /**
     * Return remaining (or partial) salesman stock to the warehouse.
     *
     * @param  array<int, array{id:int, qty:int}>  $lines
     */
    public function returnToWarehouse(Delivery $delivery, array $lines, User $actor): Delivery
    {
        return DB::transaction(function () use ($delivery, $lines, $actor) {
            $delivery = Delivery::query()->lockForUpdate()->with(['items.product', 'warehouse'])->findOrFail($delivery->id);
            $byId = collect($lines)->keyBy(fn ($row) => (int) $row['id']);

            foreach ($delivery->items as $item) {
                $qty = (int) ($byId[$item->id]['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $item->qtyRemaining()) {
                    throw ValidationException::withMessages([
                        'qty' => "{$item->product_name}: only {$item->qtyRemaining()} left with the salesman.",
                    ]);
                }

                if ($item->product && $delivery->warehouse) {
                    $this->inventory->receive(
                        $delivery->warehouse,
                        $item->product,
                        $qty,
                        $actor,
                        "Returned from {$delivery->number}",
                        $delivery->number,
                    );
                }

                $item->update([
                    'qty_returned' => (int) $item->qty_returned + $qty,
                ]);
            }

            return $delivery->fresh(['items.product', 'assignee', 'shop', 'order']);
        });
    }

    /**
     * Aggregate stock held by a salesman across open/completed deliveries.
     *
     * @return Collection<int, array{product_id:?int, sku:?string, name:string, received:int, delivered:int, returned:int, remaining:int}>
     */
    public function salesmanBalances(?User $salesman = null): Collection
    {
        $query = DeliveryItem::query()
            ->selectRaw('product_id, product_sku, product_name, SUM(qty_received) as received, SUM(qty_delivered) as delivered, SUM(qty_returned) as returned')
            ->whereHas('delivery', function ($q) use ($salesman) {
                if ($salesman) {
                    $q->where('assigned_to', $salesman->id);
                } else {
                    $q->whereNotNull('assigned_to');
                }
            })
            ->groupBy('product_id', 'product_sku', 'product_name')
            ->orderBy('product_name');

        return $query->get()->map(fn ($row) => [
            'product_id' => $row->product_id,
            'sku' => $row->product_sku,
            'name' => $row->product_name,
            'received' => (int) $row->received,
            'delivered' => (int) $row->delivered,
            'returned' => (int) $row->returned,
            'remaining' => max(0, (int) $row->received - (int) $row->delivered - (int) $row->returned),
        ]);
    }
}
