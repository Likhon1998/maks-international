<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReturnService;
use Illuminate\Database\Seeder;

class Phase7Seeder extends Seeder
{
    public function run(): void
    {
        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);
        $returns = app(ReturnService::class);
        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();

        // Ensure delivered orders have invoices (Phase 5 deliver auto-creates; backfill if missing).
        Order::query()
            ->where('status', Order::STATUS_DELIVERED)
            ->whereNull('invoice_id')
            ->with('items')
            ->get()
            ->each(function (Order $order) use ($invoices, $admin) {
                try {
                    $invoices->createFromOrder($order, $admin);
                } catch (\Throwable) {
                    // skip
                }
            });

        // Also invoice a delivered order if none exist yet (never before delivery).
        if (! Invoice::query()->exists()) {
            $order = Order::query()
                ->where('status', Order::STATUS_DELIVERED)
                ->whereNull('invoice_id')
                ->with('items')
                ->oldest('id')
                ->first();

            if ($order && $admin) {
                try {
                    $invoices->createFromOrder($order, $admin);
                } catch (\Throwable) {
                    // skip
                }
            }
        }

        $openInvoice = Invoice::query()
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->oldest('id')
            ->first();

        if ($openInvoice && $admin && ! Payment::query()->where('number', 'like', 'PAY-%')->exists()) {
            $partial = round((float) $openInvoice->total * 0.4, 2);

            $pending = $payments->record([
                'shop_id' => $openInvoice->shop_id,
                'invoice_id' => $openInvoice->id,
                'amount' => $partial,
                'method' => Payment::METHOD_BANK,
                'reference' => 'SEED-DBBL-1001',
                'paid_at' => now()->subDay(),
                'notes' => 'Seeded Phase 7 partial payment',
            ], $admin);

            $payments->verify($pending, $admin);

            // Leave a second payment pending for verify UI demo.
            $payments->record([
                'shop_id' => $openInvoice->shop_id,
                'invoice_id' => $openInvoice->id,
                'amount' => min(25000, (float) $openInvoice->fresh()->balance ?: 10000),
                'method' => Payment::METHOD_MOBILE,
                'reference' => 'SEED-BK-229100',
                'paid_at' => now(),
                'notes' => 'Seeded pending verification',
            ], $admin);
        }

        if (! ProductReturn::query()->exists() && $admin) {
            $order = Order::query()
                ->where('status', Order::STATUS_DELIVERED)
                ->whereHas('items', fn ($q) => $q->whereNotNull('product_id'))
                ->with('items')
                ->first();
            $item = $order?->items->firstWhere('product_id', '!=', null);

            if ($order && $item) {
                try {
                    $returns->create([
                        'shop_id' => $order->shop_id,
                        'order_id' => $order->id,
                        'reason_type' => ProductReturn::REASON_WARRANTY,
                        'reason' => 'Seeded warranty claim — unit DOA on arrival',
                        'restock' => true,
                    ], [
                        ['product_id' => $item->product_id, 'quantity' => 1],
                    ], $admin);
                } catch (\Throwable) {
                    // skip
                }
            }
        }
    }
}
