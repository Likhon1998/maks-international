<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Support\Collection;

class DeliveryMen
{
    /**
     * Active users who can be assigned deliveries (field delivery panel access).
     *
     * @return Collection<int, User>
     */
    public static function available(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('portal', User::PORTAL_SALESMAN)
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email']);
    }

    /**
     * @return Collection<int, array{id:int,name:string,phone:?string,open:int,assigned:int,out:int}>
     */
    public static function rosterWithLoad(): Collection
    {
        $openByAssignee = Delivery::query()
            ->whereNotNull('assigned_to')
            ->whereIn('status', [
                Delivery::STATUS_ASSIGNED,
                Delivery::STATUS_OUT_FOR_DELIVERY,
                Delivery::STATUS_FAILED,
            ])
            ->selectRaw('assigned_to, status, COUNT(*) as n')
            ->groupBy('assigned_to', 'status')
            ->get()
            ->groupBy('assigned_to');

        return self::available()->map(function (User $user) use ($openByAssignee) {
            $rows = $openByAssignee->get($user->id, collect());

            return [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'assigned' => (int) ($rows->firstWhere('status', Delivery::STATUS_ASSIGNED)?->n ?? 0),
                'out' => (int) ($rows->firstWhere('status', Delivery::STATUS_OUT_FOR_DELIVERY)?->n ?? 0),
                'failed' => (int) ($rows->firstWhere('status', Delivery::STATUS_FAILED)?->n ?? 0),
                'open' => (int) $rows->sum('n'),
            ];
        });
    }
}
