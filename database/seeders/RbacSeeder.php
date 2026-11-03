<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAccessScope;
use App\Services\RbacService;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $rbac = app(RbacService::class);
        $rbac->ensureSystemRoles();

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@maksinternational.com'],
            [
                'name' => 'Super Admin',
                'phone' => '01700000000',
                'password' => '12345678',
                'is_active' => true,
                'portal' => User::PORTAL_ADMIN,
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles(['Super Admin']);
        $admin->accessScopes()->delete();
        $admin->accessScopes()->create([
            'scope_type' => UserAccessScope::TYPE_GLOBAL,
            'label' => 'All data',
        ]);
    }
}
