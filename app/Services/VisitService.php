<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function checkIn(User $salesman, Shop $shop, array $data = [], bool $onBehalf = false): ShopVisit
    {
        // Route visits stay assigned-only. Order-on-behalf may open a visit at any active shop.
        if (! $onBehalf
            && $shop->assigned_salesman_id
            && $shop->assigned_salesman_id !== $salesman->id
            && ! $salesman->isSuperAdmin()
            && ! $salesman->hasGlobalAccessScope()) {
            throw ValidationException::withMessages([
                'shop_id' => 'This shop is not assigned to you.',
            ]);
        }

        $visit = DB::transaction(function () use ($salesman, $shop, $data) {
            // Serialise check-ins per salesman so a double tap can't open two visits.
            User::query()->lockForUpdate()->findOrFail($salesman->id);

            $open = ShopVisit::query()
                ->where('salesman_id', $salesman->id)
                ->whereNull('checked_out_at')
                ->first();

            if ($open) {
                throw ValidationException::withMessages([
                    'shop_id' => 'Check out of your current visit before starting another.',
                ]);
            }

            return ShopVisit::query()->create([
                'salesman_id' => $salesman->id,
                'shop_id' => $shop->id,
                'checked_in_at' => now(),
                'purpose' => $data['purpose'] ?? 'Order collection',
                'outcome' => ShopVisit::OUTCOME_IN_PROGRESS,
                'notes' => $data['notes'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
        });

        $this->auditLogger->log(
            'visits',
            'checked_in',
            "Checked in at {$shop->code}",
            $visit,
            null,
            ['shop_id' => $shop->id],
            $salesman,
        );

        return $visit->fresh(['shop', 'salesman']);
    }

    public function checkOut(ShopVisit $visit, array $data = [], ?User $actor = null): ShopVisit
    {
        $visit = DB::transaction(function () use ($visit, $data) {
            $visit = ShopVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! $visit->isOpen()) {
                throw ValidationException::withMessages([
                    'visit' => 'This visit is already closed.',
                ]);
            }

            $payload = [
                'checked_out_at' => now(),
                'outcome' => $data['outcome'] ?? ($visit->order_id ? ShopVisit::OUTCOME_ORDER_TAKEN : ShopVisit::OUTCOME_NO_ORDER),
                'notes' => $data['notes'] ?? $visit->notes,
            ];
            if (array_key_exists('photo_path', $data) && $data['photo_path']) {
                $payload['photo_path'] = $data['photo_path'];
            }

            $visit->update($payload);

            return $visit;
        });

        $this->auditLogger->log(
            'visits',
            'checked_out',
            "Checked out from visit #{$visit->id}",
            $visit,
            null,
            ['outcome' => $visit->outcome],
            $actor,
        );

        return $visit->fresh(['shop', 'order']);
    }

    /**
     * Discard an accidental check-in (no order). Shop stays "not visited".
     */
    public function cancelMistake(ShopVisit $visit, ?User $actor = null): ShopVisit
    {
        if ($visit->order_id) {
            throw ValidationException::withMessages([
                'visit' => 'This visit already has an order — leave normally instead.',
            ]);
        }

        $visit = DB::transaction(function () use ($visit) {
            $visit = ShopVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! $visit->isOpen()) {
                throw ValidationException::withMessages([
                    'visit' => 'This visit is already closed.',
                ]);
            }

            $visit->update([
                'checked_out_at' => now(),
                'outcome' => ShopVisit::OUTCOME_CANCELLED,
                'notes' => 'Opened by mistake — not a real visit.',
            ]);

            return $visit;
        });

        $this->auditLogger->log(
            'visits',
            'cancelled',
            "Cancelled mistaken check-in #{$visit->id}",
            $visit,
            null,
            ['shop_id' => $visit->shop_id],
            $actor,
        );

        return $visit->fresh(['shop']);
    }
}
