<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderFulfilment;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfilmentService
{
    public function __construct(
        private InventoryService $inventory,
        private AuditLogger $auditLogger,
        private InvoiceService $invoices,
        private TargetService $targets,
        private DeliveryStockService $deliveryStock,
    ) {}

    public function createForApprovedOrder(Order $order, ?Warehouse $warehouse = null, ?User $actor = null): OrderFulfilment
    {
        $warehouse ??= Warehouse::defaultWarehouse();

        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse' => 'No active warehouse configured.',
            ]);
        }

        $order->update(['warehouse_id' => $warehouse->id]);

        return OrderFulfilment::query()->create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderFulfilment::STATUS_AWAITING_PICK,
            'warehouse_notes' => 'Auto-queued after Super Admin approval',
        ]);
    }

    public function startPick(OrderFulfilment $fulfilment, User $actor): OrderFulfilment
    {
        $this->assertStatus($fulfilment, [OrderFulfilment::STATUS_AWAITING_PICK]);

        return DB::transaction(function () use ($fulfilment, $actor) {
            $fulfilment = $this->lockInStatus($fulfilment, [OrderFulfilment::STATUS_AWAITING_PICK]);
            $fulfilment->update([
                'status' => OrderFulfilment::STATUS_PICKING,
                'picker_id' => $actor->id,
                'picking_started_at' => now(),
            ]);

            $order = $fulfilment->order;
            $from = $order->status;
            $order->update(['status' => Order::STATUS_PICKING]);
            $this->history($order, $from, Order::STATUS_PICKING, 'picking_started', null, $actor);

            return $fulfilment->fresh(['order', 'warehouse', 'picker']);
        });
    }

    public function completePick(OrderFulfilment $fulfilment, User $actor, ?string $notes = null): OrderFulfilment
    {
        $this->assertStatus($fulfilment, [OrderFulfilment::STATUS_PICKING, OrderFulfilment::STATUS_AWAITING_PICK]);

        return DB::transaction(function () use ($fulfilment, $actor, $notes) {
            $fulfilment = $this->lockInStatus(
                $fulfilment,
                [OrderFulfilment::STATUS_PICKING, OrderFulfilment::STATUS_AWAITING_PICK],
                ['order.items.product', 'warehouse'],
            );
            $order = $fulfilment->order;
            $warehouse = $fulfilment->warehouse;

            if ($fulfilment->status === OrderFulfilment::STATUS_AWAITING_PICK) {
                $fulfilment->update([
                    'picker_id' => $actor->id,
                    'picking_started_at' => now(),
                ]);
            }

            foreach ($order->items as $item) {
                if (! $item->product_id || $item->reserved_quantity <= 0) {
                    continue;
                }
                $this->inventory->pick($warehouse, $item->product, (int) $item->reserved_quantity, $order, $actor);
                $item->update(['reserved_quantity' => 0]);
            }

            $order->update(['stock_reserved' => false]);

            $fulfilment->update([
                'status' => OrderFulfilment::STATUS_PICKED,
                'picker_id' => $actor->id,
                'picked_at' => now(),
                'warehouse_notes' => $notes ?: $fulfilment->warehouse_notes,
            ]);

            $from = $order->status;
            $order->update(['status' => Order::STATUS_PICKED]);
            $this->history($order, $from, Order::STATUS_PICKED, 'picked', $notes, $actor);

            $this->auditLogger->log('fulfilment', 'picked', "Order {$order->number} picked at {$warehouse->code}", $order, null, null, $actor);

            return $fulfilment->fresh(['order.items', 'warehouse', 'picker']);
        });
    }

    public function pack(OrderFulfilment $fulfilment, User $actor, ?string $notes = null): OrderFulfilment
    {
        $this->assertStatus($fulfilment, [OrderFulfilment::STATUS_PICKED]);

        return DB::transaction(function () use ($fulfilment, $actor, $notes) {
            $fulfilment = $this->lockInStatus($fulfilment, [OrderFulfilment::STATUS_PICKED]);
            $fulfilment->update([
                'status' => OrderFulfilment::STATUS_PACKED,
                'packer_id' => $actor->id,
                'packed_at' => now(),
                'warehouse_notes' => $notes ?: $fulfilment->warehouse_notes,
            ]);

            $order = $fulfilment->order;
            $from = $order->status;
            $order->update(['status' => Order::STATUS_PACKED]);
            $this->history($order, $from, Order::STATUS_PACKED, 'packed', $notes, $actor);

            return $fulfilment->fresh(['order', 'packer']);
        });
    }

    public function dispatch(OrderFulfilment $fulfilment, User $actor, array $deliveryData = []): Delivery
    {
        $this->assertStatus($fulfilment, [OrderFulfilment::STATUS_PACKED]);

        return DB::transaction(function () use ($fulfilment, $actor, $deliveryData) {
            $fulfilment = $this->lockInStatus($fulfilment, [OrderFulfilment::STATUS_PACKED], ['order.shop', 'warehouse']);
            $order = $fulfilment->order;
            $shop = $order->shop;

            $fulfilment->update([
                'status' => OrderFulfilment::STATUS_DISPATCHED,
                'dispatcher_id' => $actor->id,
                'dispatched_at' => now(),
            ]);

            $assigneeId = $deliveryData['assigned_to']
                ?? $order->salesman_id
                ?? $shop->assigned_salesman_id
                ?? null;

            if (! $assigneeId) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Assign a salesman to deliver this order.',
                ]);
            }

            $delivery = Delivery::query()->create([
                'number' => $this->nextDeliveryNumber(),
                'order_id' => $order->id,
                'fulfilment_id' => $fulfilment->id,
                'warehouse_id' => $fulfilment->warehouse_id,
                'shop_id' => $shop->id,
                'assigned_to' => $assigneeId,
                'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
                'tracking_ref' => $deliveryData['tracking_ref'] ?? null,
                'delivery_address' => $deliveryData['delivery_address'] ?? trim(($shop->address ?? '').', '.($shop->city ?? ''), ', '),
                'notes' => $deliveryData['notes'] ?? null,
                'dispatched_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->deliveryStock->seedItems($delivery);

            if (! $order->salesman_id) {
                $order->update(['salesman_id' => $assigneeId]);
            }

            $from = $order->status;
            $order->update(['status' => Order::STATUS_DISPATCHED]);
            $this->history($order, $from, Order::STATUS_DISPATCHED, 'dispatched', $delivery->number, $actor);

            $this->auditLogger->log('fulfilment', 'dispatched', "Order {$order->number} dispatched as {$delivery->number}", $order, null, null, $actor);

            return $delivery->fresh(['order', 'shop', 'assignee', 'fulfilment', 'items']);
        });
    }

    public function deliverNow(Order $order, User $actor, ?string $notes = null, ?int $assignedTo = null): Order
    {
        $order->load(['fulfilment', 'delivery', 'items.product', 'warehouse', 'shop']);

        if ($order->isDelivered()) {
            return $order;
        }

        if (! $order->canDeliver()) {
            throw ValidationException::withMessages([
                'order' => 'Approve the order first, then deliver.',
            ]);
        }

        $fulfilment = $order->fulfilment;
        if (! $fulfilment) {
            throw ValidationException::withMessages([
                'order' => 'No warehouse fulfilment is queued for this order.',
            ]);
        }

        $note = $notes ?: 'Marked delivered';
        $assignee = $assignedTo
            ?: $order->salesman_id
            ?: $order->shop?->assigned_salesman_id;

        if (in_array($fulfilment->status, [OrderFulfilment::STATUS_AWAITING_PICK, OrderFulfilment::STATUS_PICKING], true)) {
            $fulfilment = $this->completePick($fulfilment, $actor, $note);
        }

        if ($fulfilment->status === OrderFulfilment::STATUS_PICKED) {
            $fulfilment = $this->pack($fulfilment, $actor, $note);
        }

        $delivery = $order->delivery;
        if ($fulfilment->status === OrderFulfilment::STATUS_PACKED) {
            $delivery = $this->dispatch($fulfilment, $actor, [
                'notes' => $note,
                'assigned_to' => $assignee,
            ]);
        } else {
            $delivery = $delivery ?: $order->fresh('delivery')->delivery;
            if ($delivery && ! $delivery->assigned_to && $assignee) {
                $delivery->update(['assigned_to' => $assignee]);
            }
        }

        if ($delivery && $delivery->status !== Delivery::STATUS_DELIVERED) {
            $this->deliveryStock->seedItems($delivery);
            $this->deliveryStock->receive(
                $delivery,
                $delivery->fresh('items')->items->map(fn ($item) => [
                    'id' => $item->id,
                    'qty' => (int) $item->qty_ordered,
                ])->all(),
                $actor,
            );
            $this->deliveryStock->recordDeliveredQuantities(
                $delivery->fresh('items'),
                $delivery->fresh('items')->items->map(fn ($item) => [
                    'id' => $item->id,
                    'qty' => (int) $item->qty_received ?: (int) $item->qty_ordered,
                ])->all(),
                $actor,
            );
            $this->markDelivered($delivery->fresh(), $actor, $note, 0);
        }

        return $order->fresh(['fulfilment', 'delivery.items', 'invoice']);
    }

    public function markDelivered(
        Delivery $delivery,
        User $actor,
        ?string $notes = null,
        float $collectedNow = 0,
        string $method = Payment::METHOD_CASH,
        ?string $reference = null,
    ): Delivery {
        if ($delivery->status === Delivery::STATUS_DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'Already delivered.']);
        }

        $collectedNow = round($collectedNow, 2);
        if ($collectedNow < 0) {
            throw ValidationException::withMessages(['collected_now' => 'Collected amount cannot be negative.']);
        }

        return DB::transaction(function () use ($delivery, $actor, $notes, $collectedNow, $method, $reference) {
            $delivery = Delivery::query()->lockForUpdate()->with(['fulfilment', 'order'])->findOrFail($delivery->id);
            if ($delivery->status === Delivery::STATUS_DELIVERED) {
                throw ValidationException::withMessages(['delivery' => 'Already delivered.']);
            }

            $delivery->update([
                'status' => Delivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'notes' => $notes ?: $delivery->notes,
            ]);

            $fulfilment = $delivery->fulfilment;
            $fulfilment->update([
                'status' => OrderFulfilment::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]);

            $order = $delivery->order;
            $from = $order->status;
            $order->update(['status' => Order::STATUS_DELIVERED]);
            $this->history($order, $from, Order::STATUS_DELIVERED, 'delivered', $notes, $actor);

            if (! $order->invoice_id) {
                $this->invoices->createFromOrder($order->fresh('items'), $actor);
            }

            $order = $order->fresh(['invoice']);
            if ($collectedNow > 0.009) {
                if (! $order->invoice) {
                    throw ValidationException::withMessages([
                        'collected_now' => 'Cannot collect until the sales invoice is raised.',
                    ]);
                }
                app(PaymentService::class)->collectAgainstInvoice(
                    $order->invoice,
                    $collectedNow,
                    $actor,
                    $method,
                    $reference,
                    'Owner paid at the shop when the goods were delivered',
                    applyNow: true,
                );
            }

            $this->targets->recalculateFor($order->salesman_id);

            $this->auditLogger->log('deliveries', 'delivered', "Delivery {$delivery->number} completed", $delivery, null, null, $actor);

            $fresh = $delivery->fresh(['order.invoice', 'order.salesman', 'shop', 'fulfilment']);

            DB::afterCommit(function () use ($fresh) {
                try {
                    app(AppNotificationService::class)->orderDelivered($fresh->order);
                } catch (\Throwable) {
                    // non-blocking
                }
            });

            return $fresh;
        });
    }

    public function markFailed(Delivery $delivery, User $actor, ?string $notes = null): Delivery
    {
        if ($delivery->status === Delivery::STATUS_DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'A completed delivery cannot be marked failed.']);
        }

        if ($delivery->status === Delivery::STATUS_FAILED) {
            return $delivery->fresh(['order', 'shop', 'fulfilment']);
        }

        return DB::transaction(function () use ($delivery, $actor, $notes) {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $delivery->update([
                'status' => Delivery::STATUS_FAILED,
                'notes' => $notes ?: $delivery->notes,
            ]);

            $this->auditLogger->log('deliveries', 'failed', "Delivery {$delivery->number} marked failed", $delivery, null, null, $actor);

            return $delivery->fresh(['order', 'shop', 'fulfilment']);
        });
    }

    public function retryDelivery(Delivery $delivery, User $actor, ?string $notes = null): Delivery
    {
        if ($delivery->status !== Delivery::STATUS_FAILED) {
            throw ValidationException::withMessages(['delivery' => 'Only a failed delivery can be sent out again.']);
        }

        return DB::transaction(function () use ($delivery, $actor, $notes) {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $delivery->update([
                'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
                'notes' => $notes ?: $delivery->notes,
            ]);

            $this->auditLogger->log('deliveries', 'retried', "Delivery {$delivery->number} sent out again", $delivery, null, null, $actor);

            return $delivery->fresh(['order', 'shop', 'fulfilment']);
        });
    }

    public function nextDeliveryNumber(): string
    {
        return \App\Support\DocumentNumber::next(Delivery::class, 'DLV-'.now()->format('ymd').'-');
    }

    private function assertStatus(OrderFulfilment $fulfilment, array $allowed): void
    {
        if (! in_array($fulfilment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Invalid fulfilment step for status '.$fulfilment->statusLabel().'.',
            ]);
        }
    }

    /** Re-read the fulfilment under a row lock and re-check its status (call inside a transaction). */
    private function lockInStatus(OrderFulfilment $fulfilment, array $allowed, array $with = ['order']): OrderFulfilment
    {
        // Same lock order as OrderService::cancel (order, then fulfilment) so the two never interleave.
        Order::query()->lockForUpdate()->findOrFail($fulfilment->order_id);
        $fulfilment = OrderFulfilment::query()->lockForUpdate()->with($with)->findOrFail($fulfilment->id);
        if ($fulfilment->order?->status === Order::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['fulfilment' => 'This order was cancelled.']);
        }
        $this->assertStatus($fulfilment, $allowed);

        return $fulfilment;
    }

    private function history(Order $order, ?string $from, string $to, string $event, ?string $notes, ?User $actor): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'notes' => $notes,
            'user_id' => $actor?->id,
        ]);
    }
}
