<?php

namespace App\Services;

use App\Models\PartnerInquiry;
use App\Models\PriceGroup;
use App\Models\Shop;
use App\Models\User;
use App\Rules\BangladeshPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PartnerInquiryService
{
    public function __construct(
        private ShopService $shops,
        private WhatsAppService $whatsapp,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Accept lead → active shop. Website leads get a portal login (email + temp password) right away;
     * field leads wait for the submitting salesman to create the login with the owner.
     *
     * @return array{inquiry: PartnerInquiry, shop: Shop, email: ?string, password: ?string, whatsapp: array, login_by_field: bool}
     */
    public function accept(PartnerInquiry $inquiry, User $actor): array
    {
        $loginByField = $inquiry->isFromField() && $inquiry->submitted_by !== null;

        if ($inquiry->status === PartnerInquiry::STATUS_CONVERTED && $inquiry->shop_id) {
            throw new RuntimeException('This request is already accepted.');
        }

        if (! $inquiry->email) {
            throw new RuntimeException('This request has no email for portal login.');
        }

        if (! $loginByField) {
            try {
                $this->shops->assertLoginEmailFree(
                    ($inquiry->shop_id ? Shop::query()->find($inquiry->shop_id) : null) ?? new Shop(),
                    $inquiry->email,
                );
            } catch (ValidationException $e) {
                throw new RuntimeException(collect($e->errors())->flatten()->first());
            }
        }

        $password = $loginByField ? null : $this->temporaryPassword();

        $result = DB::transaction(function () use ($inquiry, $actor, $password, $loginByField) {
            $inquiry = PartnerInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
            if ($inquiry->status === PartnerInquiry::STATUS_CONVERTED) {
                throw new RuntimeException('This request is already accepted.');
            }

            $priceGroupId = PriceGroup::query()->where('is_active', true)->orderBy('id')->value('id');
            $credential = $loginByField ? null : [
                'email' => $inquiry->email,
                'password' => $password,
                'name' => $inquiry->contact_name ?: $inquiry->business_name,
                'phone' => $inquiry->phone,
            ];

            $phone = $inquiry->phone ? BangladeshPhone::normalize($inquiry->phone) : null;
            $existing = $inquiry->shop_id ? Shop::query()->find($inquiry->shop_id) : null;

            if (! $existing && $phone) {
                $samePhone = Shop::query()->where('phone', $phone)->first();
                if ($samePhone) {
                    $alreadyClaimed = $samePhone->users()->exists()
                        || PartnerInquiry::query()
                            ->where('shop_id', $samePhone->id)
                            ->where('status', PartnerInquiry::STATUS_CONVERTED)
                            ->exists();
                    if ($alreadyClaimed) {
                        throw new RuntimeException("{$phone} already belongs to {$samePhone->name} ({$samePhone->code}). This looks like an existing shop — update that shop instead of creating a duplicate.");
                    }
                    // Reuse an unclaimed shop (e.g. field-added pending record) instead of blocking approval.
                    $existing = $samePhone;
                }
            }

            if ($existing) {
                $existing->update(array_filter([
                    'name' => $existing->name ?: $inquiry->business_name,
                    'owner_name' => $existing->owner_name ?: $inquiry->contact_name,
                    'phone' => $existing->phone ?: ($phone ?? $inquiry->phone),
                    'email' => $existing->email ?: $inquiry->email,
                    'city' => $existing->city ?: $inquiry->city,
                    'price_group_id' => $existing->price_group_id ?: $priceGroupId,
                    'assigned_salesman_id' => $existing->assigned_salesman_id
                        ?: ($inquiry->isFromField() ? $inquiry->submitted_by : null),
                ], fn ($v) => $v !== null && $v !== ''));

                if ($existing->status === Shop::STATUS_ON_HOLD) {
                    throw new RuntimeException("{$existing->name} is on hold. Take it off hold on the shop page before accepting this request.");
                }
                if ($existing->status === Shop::STATUS_REJECTED) {
                    throw new RuntimeException("{$existing->name} was rejected. Change its status on the shop page before accepting this request.");
                }
                $shop = $existing->status === Shop::STATUS_ACTIVE ? $existing : $this->shops->approve($existing, $actor);
                if ($credential) {
                    $this->shops->attachShopUser($shop, $credential, $actor);
                }
                $shop = $shop->fresh();
            } else {
                $shop = $this->shops->create([
                    'name' => $inquiry->business_name,
                    'owner_name' => $inquiry->contact_name ?: $inquiry->business_name,
                    'phone' => $phone ?? $inquiry->phone,
                    'email' => $inquiry->email,
                    'city' => $inquiry->city,
                    'price_group_id' => $priceGroupId,
                    'assigned_salesman_id' => $inquiry->isFromField() ? $inquiry->submitted_by : null,
                    'credit_limit' => 150000,
                    'payment_terms_days' => 21,
                    'status' => Shop::STATUS_ACTIVE,
                    'notes' => 'Accepted from partner application #'.$inquiry->id,
                ], $actor, $credential);
            }

            $inquiry->update([
                'status' => PartnerInquiry::STATUS_CONVERTED,
                'shop_id' => $shop->id,
                'portal_email' => $loginByField ? null : $inquiry->email,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'admin_notes' => trim(($inquiry->admin_notes ? $inquiry->admin_notes."\n" : '').'Accepted · shop '.$shop->code),
            ]);

            $this->auditLogger->log(
                'partners',
                'accepted',
                "Accepted partner application for {$inquiry->business_name}",
                $inquiry->fresh(),
                null,
                ['shop_id' => $shop->id, 'portal_email' => $loginByField ? null : $inquiry->email, 'login_by_field' => $loginByField],
                $actor,
            );

            return [
                'inquiry' => $inquiry->fresh(),
                'shop' => $shop,
                'email' => $loginByField ? null : $inquiry->email,
                'password' => $password,
                'whatsapp' => [],
                'login_by_field' => $loginByField,
            ];
        });

        // Keep WhatsApp outside the DB transaction so a messaging failure never undoes approval.
        if (! $result['login_by_field']) {
            $result['whatsapp'] = $this->whatsapp->notifyApproval(
                $result['inquiry']->phone,
                $result['shop']->name,
                $result['email'],
                $result['password'],
            );
        }

        return $result;
    }

    /**
     * Create or reset the portal login for an accepted shop, then check it works the same way the
     * partner login does (password, active account, shop portal, linked to an active shop).
     *
     * @return array{user: User, shop: Shop, checks: array<string, bool>, ok: bool, whatsapp: array}
     */
    public function setLogin(PartnerInquiry $inquiry, string $email, string $password, User $actor): array
    {
        $shop = $inquiry->shop;

        if (! $inquiry->isAccepted() || ! $shop) {
            throw new RuntimeException('The office has not accepted this application yet.');
        }

        $email = strtolower(trim($email));
        $current = $inquiry->portal_email
            ? User::query()->where('email', $inquiry->portal_email)->where('portal', User::PORTAL_SHOP)->first()
            : null;
        $taken = User::query()->where('email', $email)->first();

        if ($taken && $taken->portal !== User::PORTAL_SHOP) {
            throw new RuntimeException('This email belongs to a staff or salesman account. Use the shop owner\'s own email.');
        }
        if ($taken && $taken->id !== $current?->id && $taken->shops()->where('shops.id', '!=', $shop->id)->exists()) {
            throw new RuntimeException('This email is already the login of another shop.');
        }

        $user = DB::transaction(function () use ($inquiry, $shop, $email, $password, $actor, $current, $taken) {
            if ($current && ! $taken && $current->email !== $email) {
                $current->forceFill(['email' => $email])->save();
            } elseif ($current && $taken && $taken->id !== $current->id) {
                $shop->users()->detach($current->id);
                $current->forceFill(['is_active' => false])->save();
            }

            $user = $this->shops->attachShopUser($shop, [
                'email' => $email,
                'password' => $password,
                'name' => $inquiry->contact_name ?: $shop->owner_name ?: $shop->name,
                'phone' => $inquiry->phone ?: $shop->phone,
            ], $actor);

            $inquiry->update(['portal_email' => $email]);

            $this->auditLogger->log(
                'partners',
                $current ? 'login_reset' : 'login_created',
                "Portal login for {$shop->code} set by {$actor->name}",
                $inquiry->fresh(),
                null,
                ['portal_email' => $email],
                $actor,
            );

            return $user;
        });

        $user = $user->fresh();
        $linked = $user->primaryShop();
        $checks = [
            'account' => $user->portal === User::PORTAL_SHOP && $user->is_active,
            'password' => Hash::check($password, $user->password),
            'shop' => $linked?->id === $shop->id,
            'active' => $linked?->status === Shop::STATUS_ACTIVE,
        ];

        return [
            'user' => $user,
            'shop' => $shop->fresh(),
            'checks' => $checks,
            'ok' => ! in_array(false, $checks, true),
            'whatsapp' => $this->whatsapp->notifyApproval($inquiry->phone ?: $shop->phone, $shop->name, $email, $password),
        ];
    }

    public function submitFromField(array $data, User $salesman): PartnerInquiry
    {
        $inquiry = PartnerInquiry::query()->create([
            'contact_name' => $data['contact_name'] ?? '',
        ] + $data + [
            'status' => PartnerInquiry::STATUS_NEW,
            'source' => PartnerInquiry::SOURCE_FIELD,
            'submitted_by' => $salesman->id,
        ]);

        $this->auditLogger->log(
            'partners',
            'submitted',
            "Field application for {$inquiry->business_name} by {$salesman->name}",
            $inquiry,
            null,
            $inquiry->only(['business_name', 'email', 'phone', 'shop_id']),
            $salesman,
        );

        return $inquiry;
    }

    public function reject(PartnerInquiry $inquiry, User $actor, ?string $notes = null): PartnerInquiry
    {
        if ($inquiry->status === PartnerInquiry::STATUS_CONVERTED) {
            throw ValidationException::withMessages(['inquiry' => 'This request was already accepted and its shop created — manage the shop instead.']);
        }

        $inquiry->update([
            'status' => PartnerInquiry::STATUS_CLOSED,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'admin_notes' => $notes ?: $inquiry->admin_notes,
        ]);

        $this->auditLogger->log('partners', 'rejected', "Rejected partner application for {$inquiry->business_name}", $inquiry, null, null, $actor);

        return $inquiry->fresh();
    }

    public function markPending(PartnerInquiry $inquiry, User $actor): PartnerInquiry
    {
        if ($inquiry->status === PartnerInquiry::STATUS_CONVERTED) {
            throw ValidationException::withMessages(['inquiry' => 'This request was already accepted and cannot be reopened.']);
        }

        $inquiry->update([
            'status' => PartnerInquiry::STATUS_NEW,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
        ]);

        return $inquiry->fresh();
    }

    public function whatsappPayload(PartnerInquiry $inquiry, string $password): array
    {
        $email = $inquiry->portal_email ?: $inquiry->email;
        $shopName = $inquiry->shop?->name ?: $inquiry->business_name;

        return $this->whatsapp->notifyApproval(
            $inquiry->phone,
            $shopName,
            $email,
            $password,
        );
    }

    public function temporaryPassword(): string
    {
        // Readable temp password for WhatsApp (no ambiguous chars)
        return 'Bt'.Str::upper(Str::random(4)).rand(10, 99);
    }
}
