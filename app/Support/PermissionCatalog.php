<?php

namespace App\Support;

class PermissionCatalog
{
    /**
     * Module => [permission_suffix => label]
     *
     * @return array<string, array<string, string>>
     */
    public static function modules(): array
    {
        return [
            'users' => [
                'view' => 'View users',
                'create' => 'Create users',
                'edit' => 'Edit users',
                'delete' => 'Delete users',
                'activate' => 'Activate / deactivate users',
                'reset_password' => 'Reset credentials',
            ],
            'roles' => [
                'view' => 'View roles',
                'create' => 'Create roles',
                'edit' => 'Edit roles',
                'delete' => 'Delete roles',
                'assign' => 'Assign roles to users',
            ],
            'permissions' => [
                'view' => 'View permissions',
                'manage' => 'Manage permission assignments',
            ],
            'audit' => [
                'view' => 'View audit logs',
            ],
            'shops' => [
                'view' => 'View shops',
                'create' => 'Create shops',
                'edit' => 'Edit shops',
                'delete' => 'Delete shops',
                'approve' => 'Approve shops',
                'manage_credentials' => 'Manage shop credentials',
                'manage_credit' => 'Change shop credit limit and payment terms',
            ],
            'categories' => [
                'view' => 'View categories',
                'create' => 'Create categories',
                'edit' => 'Edit categories',
                'delete' => 'Delete categories',
            ],
            'brands' => [
                'view' => 'View brands',
                'create' => 'Create brands',
                'edit' => 'Edit brands',
                'delete' => 'Delete brands',
            ],
            'price_groups' => [
                'view' => 'View price groups',
                'manage' => 'Manage price groups and product prices',
            ],
            'salesmen' => [
                'view' => 'View salesmen',
                'create' => 'Create salesmen',
                'edit' => 'Edit salesmen',
                'delete' => 'Delete salesmen',
            ],
            'visits' => [
                'view' => 'View shop visits',
                'create' => 'Record shop visits',
                'edit' => 'Edit shop visits',
            ],
            'orders' => [
                'view' => 'View orders',
                'create' => 'Create orders',
                'edit' => 'Edit orders',
                'approve' => 'Approve orders',
                'credit_override' => 'Legacy credit override (orders are not blocked by limit)',
                'reject' => 'Reject orders',
                'cancel' => 'Cancel orders',
                'delete' => 'Delete orders',
                'export' => 'Export orders',
            ],
            'products' => [
                'view' => 'View products',
                'create' => 'Create products',
                'edit' => 'Edit products',
                'delete' => 'Delete products',
                'manage_price' => 'Manage product prices',
                'manage_stock' => 'Manage product stock',
                'import' => 'Import products',
                'export' => 'Export products',
            ],
            'inventory' => [
                'view' => 'View inventory',
                'receive' => 'Receive stock',
                'adjust' => 'Adjust stock',
                'transfer' => 'Transfer stock',
            ],
            'warehouses' => [
                'view' => 'View warehouses',
                'create' => 'Create warehouses',
                'edit' => 'Edit warehouses',
                'delete' => 'Delete warehouses',
            ],
            'fulfilment' => [
                'view' => 'View fulfilment queue',
                'pick' => 'Pick orders',
                'pack' => 'Pack orders',
                'dispatch' => 'Dispatch orders',
            ],
            'shipments' => [
                'view' => 'View shipments',
                'create' => 'Create shipments',
                'edit' => 'Edit shipments',
                'delete' => 'Delete shipments',
                'receive' => 'Receive shipments',
            ],
            'suppliers' => [
                'view' => 'View suppliers',
                'create' => 'Create suppliers',
                'edit' => 'Edit suppliers',
                'delete' => 'Delete suppliers',
            ],
            'purchases' => [
                'view' => 'View purchases',
                'create' => 'Create purchases',
                'edit' => 'Edit purchases',
                'delete' => 'Delete purchases',
            ],
            'invoices' => [
                'view' => 'View invoices',
                'create' => 'Create invoices',
                'edit' => 'Edit invoices',
                'export' => 'Export invoices',
            ],
            'payments' => [
                'view' => 'View payments',
                'create' => 'Create payments',
                'verify' => 'Verify payments',
                'delete' => 'Delete payments',
            ],
            'returns' => [
                'view' => 'View returns',
                'create' => 'Create returns',
                'approve' => 'Approve returns',
                'reject' => 'Reject returns',
            ],
            'deliveries' => [
                'view' => 'View deliveries',
                'assign' => 'Assign deliveries',
                'update_status' => 'Update delivery status',
            ],
            'targets' => [
                'view' => 'View targets',
                'manage' => 'Manage targets',
            ],
            'commissions' => [
                'view' => 'View commissions',
                'manage' => 'Manage commissions',
                'approve' => 'Approve commission payouts',
            ],
            'rewards' => [
                'view' => 'View rewards',
                'manage' => 'Manage rewards',
                'approve' => 'Approve / award rewards',
            ],
            'dues' => [
                'view' => 'View due management',
                'export' => 'Export due management reports',
            ],
            'salaries' => [
                'view' => 'View salesman salaries',
                'manage' => 'Manage salary and payroll',
            ],
            'announcements' => [
                'view' => 'View announcements',
                'manage' => 'Send announcements to salesmen',
            ],
            'reports' => [
                'view' => 'View reports',
                'export' => 'Export reports',
            ],
            'analytics' => [
                'view' => 'View analytics',
            ],
            'settings' => [
                'view' => 'View settings',
                'manage' => 'Manage settings',
            ],
            'website' => [
                'view' => 'View public website content',
                'manage' => 'Edit public website content',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::modules() as $module => $actions) {
            foreach (array_keys($actions) as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return $permissions;
    }

    public static function label(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, null);

        return self::modules()[$module][$action] ?? $permission;
    }

    /**
     * Recommended permission sets keyed by system role name.
     *
     * @return array<string, list<string>>
     */
    public static function rolePresets(): array
    {
        $all = self::all();

        return [
            'Super Admin' => $all,
            'Admin' => array_values(array_filter(
                $all,
                fn (string $p) => $p !== 'roles.delete' && ! str_starts_with($p, 'permissions.')
            )),
            'Sales Manager' => [
                'shops.view', 'shops.create', 'shops.edit', 'shops.approve', 'shops.manage_credentials',
                'salesmen.view', 'salesmen.create', 'salesmen.edit',
                'visits.view', 'visits.create', 'visits.edit',
                'orders.view', 'orders.create', 'orders.edit', 'orders.approve', 'orders.reject', 'orders.cancel', 'orders.export',
                'products.view', 'products.create', 'products.edit',
                'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
                'brands.view', 'brands.create', 'brands.edit', 'brands.delete',
                'price_groups.view', 'dues.view', 'dues.export', 'announcements.view', 'announcements.manage',
                'returns.view', 'returns.create', 'returns.approve', 'returns.reject',
                'reports.view', 'analytics.view', 'targets.view', 'targets.manage', 'commissions.view', 'rewards.view',
            ],
            'Salesman' => [
                'shops.view', 'shops.create', 'shops.approve',
                'visits.view', 'visits.create', 'visits.edit',
                'orders.view', 'orders.create', 'products.view',
                'targets.view', 'commissions.view', 'rewards.view', 'deliveries.view',
            ],
            'Warehouse Manager' => [
                'warehouses.view', 'warehouses.create', 'warehouses.edit',
                'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.transfer',
                'fulfilment.view', 'fulfilment.pick', 'fulfilment.pack', 'fulfilment.dispatch',
                'shipments.view', 'shipments.create', 'shipments.edit', 'shipments.receive',
                'suppliers.view', 'purchases.view', 'purchases.create',
                'orders.view', 'products.view', 'products.manage_stock',
                'deliveries.view', 'deliveries.assign', 'deliveries.update_status',
                'returns.view', 'returns.approve', 'returns.reject',
            ],
            'Warehouse Staff' => [
                'warehouses.view', 'inventory.view', 'inventory.receive',
                'fulfilment.view', 'fulfilment.pick', 'fulfilment.pack',
                'orders.view', 'products.view', 'shipments.view', 'shipments.receive', 'deliveries.view',
            ],
            'Finance Manager' => [
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.export',
                'payments.view', 'payments.create', 'payments.verify',
                'orders.view', 'shops.view', 'shops.edit', 'shops.manage_credit', 'returns.view', 'returns.approve',
                'dues.view', 'dues.export', 'salaries.view', 'salaries.manage',
                'reports.view', 'analytics.view', 'commissions.view', 'commissions.approve', 'commissions.manage',
                'targets.view', 'targets.manage', 'rewards.view', 'rewards.manage', 'rewards.approve',
            ],
            'Delivery Manager' => [
                'deliveries.view', 'deliveries.assign', 'deliveries.update_status',
                'fulfilment.view', 'fulfilment.dispatch',
                'orders.view', 'shops.view',
            ],
            'Delivery Staff' => [
                'deliveries.view', 'deliveries.update_status', 'orders.view', 'fulfilment.view',
            ],
            'Shop Owner' => [
                'orders.view', 'orders.create', 'products.view',
                'invoices.view', 'payments.view', 'returns.view', 'returns.create',
            ],
            'Accountant' => [
                'invoices.view', 'invoices.export', 'payments.view', 'payments.verify',
                'dues.view', 'dues.export', 'salaries.view',
                'reports.view', 'analytics.view', 'shops.view', 'orders.view',
            ],
        ];
    }

    /**
     * Short descriptions for role preset picker UI.
     *
     * @return array<string, string>
     */
    public static function rolePresetDescriptions(): array
    {
        return [
            'Super Admin' => 'Full system access including roles and permissions.',
            'Admin' => 'Broad admin access without deleting roles or managing the permission catalog.',
            'Sales Manager' => 'Shops, sales team, orders, pricing overview and sales reporting.',
            'Salesman' => 'Field sales: shop visits, create orders, view targets and commissions.',
            'Warehouse Manager' => 'Warehouses, stock, fulfilment, shipments and purchases.',
            'Warehouse Staff' => 'Pick, pack, receive stock and view related orders.',
            'Finance Manager' => 'Invoices, payments, returns approval and financial reports.',
            'Delivery Manager' => 'Assign and track deliveries and dispatch.',
            'Delivery Staff' => 'Update delivery status for assigned routes.',
            'Shop Owner' => 'Shop portal: place orders, view invoices and payments.',
            'Accountant' => 'Read invoices/payments and export financial reports.',
        ];
    }
}
