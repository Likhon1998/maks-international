<?php

namespace Database\Seeders;

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reward;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\RbacService;
use App\Services\TargetService;
use Illuminate\Database\Seeder;

class Phase8Seeder extends Seeder
{
    public function run(): void
    {
        app(RbacService::class)->ensureSystemRoles();

        $admin = User::query()->where('email', 'admin@maksinternational.com')->first();
        $targets = app(TargetService::class);
        $commissions = app(CommissionService::class);

        CommissionRule::query()->updateOrCreate(
            ['name' => 'Default salesman commission'],
            [
                'collection_rate_percent' => 3.5,
                'target_bonus_percent' => 1.0,
                'is_default' => true,
                'is_active' => true,
                'notes' => '3.5% on verified collections + 1% target bonus when monthly target is met',
            ],
        );

        $targets->seedCurrentMonthFromProfiles($admin);

        // Lower one target so seed data can hit it for reward/bonus demo.
        $salesman = User::query()->where('email', 'karim@field.local')->first()
            ?: User::query()->where('portal', User::PORTAL_SALESMAN)->first();

        if ($salesman) {
            $year = (int) now()->year;
            $month = (int) now()->month;

            // Ensure a delivered order is attributed to this salesman for achievement.
            $order = Order::query()
                ->where('status', Order::STATUS_DELIVERED)
                ->latest('id')
                ->first();

            if ($order && ! $order->salesman_id) {
                $order->update(['salesman_id' => $salesman->id]);
            } elseif ($order && $order->salesman_id) {
                $salesman = User::query()->find($order->salesman_id) ?: $salesman;
            }

            $achievedHint = (float) Order::query()
                ->where('salesman_id', $salesman->id)
                ->where('status', Order::STATUS_DELIVERED)
                ->sum('total');

            $targets->upsert([
                'salesman_id' => $salesman->id,
                'year' => $year,
                'month' => $month,
                'target_amount' => max(10000, round($achievedHint * 0.5, 2)),
            ], $admin);

            // Backfill commission for already-verified payments linked to salesman orders.
            Payment::query()
                ->where('status', Payment::STATUS_VERIFIED)
                ->with(['invoice.order', 'invoice.shop', 'shop'])
                ->get()
                ->each(function (Payment $payment) use ($salesman) {
                    $order = $payment->invoice?->order;
                    if ($order && ! $order->salesman_id) {
                        $order->update(['salesman_id' => $salesman->id]);
                    }
                    $shop = $payment->invoice?->shop ?: $payment->shop;
                    if ($shop && ! $shop->assigned_salesman_id) {
                        $shop->update(['assigned_salesman_id' => $salesman->id]);
                    }
                });

            $sync = $commissions->syncFromVerifiedPayments($admin);
            unset($sync);

            $targets->recalculatePeriod($year, $month);

            $target = SalesTarget::query()
                ->where('salesman_id', $salesman->id)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($target) {
                $commissions->maybeAccrueTargetBonus($target->fresh(), $admin);
            }
        }

        // Approve first accrued commission for UI demo.
        $first = Commission::query()->where('status', Commission::STATUS_ACCRUED)->oldest('id')->first();
        if ($first && $admin) {
            try {
                $commissions->approve($first, $admin);
            } catch (\Throwable) {
                // skip
            }
        }
    }
}
