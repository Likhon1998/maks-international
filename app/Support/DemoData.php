<?php

namespace App\Support;

class DemoData
{
    public static function taka(int|float|string|null $amount): string
    {
        $amount = (float) ($amount ?? 0);
        $n = (string) abs((int) round($amount));
        $last3 = substr($n, -3);
        $rest = substr($n, 0, -3);
        $grouped = $rest !== ''
            ? strrev(implode(',', str_split(strrev($rest), 2))).','.$last3
            : $last3;

        return ($amount < 0 ? '-৳ ' : '৳ ').$grouped;
    }

    public static function shops(): array
    {
        return [
            ['id' => 'SHP-1042', 'name' => 'Tech Zone', 'owner' => 'Rafiq Hasan', 'city' => 'Dhaka', 'salesman' => 'Karim Uddin', 'credit' => 250000, 'outstanding' => 84500, 'orders' => 128, 'status' => 'Active'],
            ['id' => 'SHP-1043', 'name' => 'Gadget Hub', 'owner' => 'Nusrat Jahan', 'city' => 'Chattogram', 'salesman' => 'Farhana Akter', 'credit' => 180000, 'outstanding' => 32000, 'orders' => 96, 'status' => 'Active'],
            ['id' => 'SHP-1044', 'name' => 'Mobile World', 'owner' => 'Imran Hossain', 'city' => 'Sylhet', 'salesman' => 'Karim Uddin', 'credit' => 120000, 'outstanding' => 0, 'orders' => 74, 'status' => 'Active'],
            ['id' => 'SHP-1045', 'name' => 'Smart Plaza', 'owner' => 'Tanvir Ahmed', 'city' => 'Khulna', 'salesman' => 'Mehedi Hasan', 'credit' => 200000, 'outstanding' => 156000, 'orders' => 61, 'status' => 'On Hold'],
            ['id' => 'SHP-1046', 'name' => 'Electro Mart', 'owner' => 'Sadia Rahman', 'city' => 'Rajshahi', 'salesman' => 'Farhana Akter', 'credit' => 150000, 'outstanding' => 18500, 'orders' => 88, 'status' => 'Active'],
            ['id' => 'SHP-1047', 'name' => 'City Gadgets', 'owner' => 'Mahmudul Islam', 'city' => 'Dhaka', 'salesman' => 'Mehedi Hasan', 'credit' => 300000, 'outstanding' => 92000, 'orders' => 154, 'status' => 'Pending Approval'],
        ];
    }

    public static function salesmen(): array
    {
        return [
            ['id' => 'SM-201', 'name' => 'Karim Uddin', 'phone' => '01711-234567', 'territory' => 'Dhaka North', 'shops' => 42, 'orders' => 186, 'target' => 850000, 'achieved' => 712000, 'commission' => 28480, 'status' => 'Active'],
            ['id' => 'SM-202', 'name' => 'Farhana Akter', 'phone' => '01819-887766', 'territory' => 'Chattogram', 'shops' => 36, 'orders' => 154, 'target' => 720000, 'achieved' => 698000, 'commission' => 24430, 'status' => 'Active'],
            ['id' => 'SM-203', 'name' => 'Mehedi Hasan', 'phone' => '01612-445566', 'territory' => 'Khulna & Barishal', 'shops' => 28, 'orders' => 97, 'target' => 540000, 'achieved' => 318000, 'commission' => 11130, 'status' => 'Active'],
            ['id' => 'SM-204', 'name' => 'Shahriar Kabir', 'phone' => '01913-221100', 'territory' => 'Sylhet', 'shops' => 21, 'orders' => 64, 'target' => 400000, 'achieved' => 221000, 'commission' => 7735, 'status' => 'On Leave'],
        ];
    }

    public static function orders(): array
    {
        return [
            ['no' => 'ORD-2505-1289', 'shop' => 'Tech Zone', 'salesman' => 'Karim Uddin', 'amount' => 84500, 'status' => 'Pending Approval', 'date' => '28 May 2025', 'source' => 'Shop Portal'],
            ['no' => 'ORD-2505-1288', 'shop' => 'Gadget Hub', 'salesman' => 'Farhana Akter', 'amount' => 126000, 'status' => 'Approved', 'date' => '28 May 2025', 'source' => 'Salesman'],
            ['no' => 'ORD-2505-1284', 'shop' => 'Mobile World', 'salesman' => 'Karim Uddin', 'amount' => 54200, 'status' => 'Processing', 'date' => '27 May 2025', 'source' => 'Shop Portal'],
            ['no' => 'ORD-2505-1279', 'shop' => 'Electro Mart', 'salesman' => 'Farhana Akter', 'amount' => 97800, 'status' => 'Shipped', 'date' => '26 May 2025', 'source' => 'Salesman'],
            ['no' => 'ORD-2505-1271', 'shop' => 'City Gadgets', 'salesman' => 'Mehedi Hasan', 'amount' => 210400, 'status' => 'Delivered', 'date' => '25 May 2025', 'source' => 'Shop Portal'],
            ['no' => 'ORD-2505-1266', 'shop' => 'Smart Plaza', 'salesman' => 'Mehedi Hasan', 'amount' => 67300, 'status' => 'Pending Approval', 'date' => '25 May 2025', 'source' => 'Salesman'],
        ];
    }

    public static function products(): array
    {
        return [
            ['sku' => 'BT-EB-01', 'name' => 'Bluetooth Earbuds', 'category' => 'Audio', 'stock' => 1245, 'reserved' => 86, 'price' => 1250, 'landed' => 780, 'status' => 'In Stock'],
            ['sku' => 'PW-BK-20', 'name' => 'Power Bank 20,000 mAh', 'category' => 'Power', 'stock' => 860, 'reserved' => 42, 'price' => 1850, 'landed' => 1120, 'status' => 'In Stock'],
            ['sku' => 'SM-WT-09', 'name' => 'Smart Watch Series 9', 'category' => 'Wearables', 'stock' => 214, 'reserved' => 31, 'price' => 4200, 'landed' => 2650, 'status' => 'Low Stock'],
            ['sku' => 'CG-CH-33', 'name' => 'USB-C Fast Charger 33W', 'category' => 'Accessories', 'stock' => 2104, 'reserved' => 120, 'price' => 450, 'landed' => 210, 'status' => 'In Stock'],
            ['sku' => 'TB-HD-11', 'name' => 'Tablet Holder Desk Mount', 'category' => 'Accessories', 'stock' => 0, 'reserved' => 0, 'price' => 890, 'landed' => 410, 'status' => 'Out of Stock'],
            ['sku' => 'SP-BT-12', 'name' => 'Portable Bluetooth Speaker', 'category' => 'Audio', 'stock' => 532, 'reserved' => 18, 'price' => 2650, 'landed' => 1580, 'status' => 'In Stock'],
        ];
    }

    public static function shipments(): array
    {
        return [
            ['id' => 'SH-2505-001', 'origin' => 'Shenzhen, China', 'carrier' => 'COSCO', 'items' => 1840, 'cost' => 428000, 'eta' => '02 Jun 2025', 'status' => 'In Transit'],
            ['id' => 'SH-2505-002', 'origin' => 'Guangzhou, China', 'carrier' => 'Maersk', 'items' => 960, 'cost' => 312500, 'eta' => '30 May 2025', 'status' => 'Customs'],
            ['id' => 'SH-2504-018', 'origin' => 'Shenzhen, China', 'carrier' => 'Evergreen', 'items' => 2210, 'cost' => 615000, 'eta' => '18 May 2025', 'status' => 'Delivered'],
            ['id' => 'SH-2505-003', 'origin' => 'Yiwu, China', 'carrier' => 'Hapag-Lloyd', 'items' => 740, 'cost' => 198000, 'eta' => '12 Jun 2025', 'status' => 'Pending'],
        ];
    }

    public static function invoices(): array
    {
        return [
            ['no' => 'INV-2505-441', 'shop' => 'Tech Zone', 'order' => 'ORD-2505-1271', 'amount' => 210400, 'paid' => 120000, 'due' => '10 Jun 2025', 'status' => 'Partial'],
            ['no' => 'INV-2505-438', 'shop' => 'Gadget Hub', 'order' => 'ORD-2505-1260', 'amount' => 88600, 'paid' => 88600, 'due' => '22 May 2025', 'status' => 'Paid'],
            ['no' => 'INV-2505-429', 'shop' => 'Electro Mart', 'order' => 'ORD-2505-1248', 'amount' => 156200, 'paid' => 0, 'due' => '05 Jun 2025', 'status' => 'Unpaid'],
            ['no' => 'INV-2505-421', 'shop' => 'Mobile World', 'order' => 'ORD-2505-1239', 'amount' => 45200, 'paid' => 45200, 'due' => '18 May 2025', 'status' => 'Paid'],
        ];
    }

    public static function payments(): array
    {
        return [
            ['id' => 'PAY-9021', 'shop' => 'Gadget Hub', 'invoice' => 'INV-2505-438', 'amount' => 88600, 'method' => 'Bank Transfer', 'ref' => 'DBBL-88321', 'date' => '22 May 2025', 'status' => 'Cleared'],
            ['id' => 'PAY-9018', 'shop' => 'Tech Zone', 'invoice' => 'INV-2505-441', 'amount' => 120000, 'method' => 'Cheque', 'ref' => 'CHQ-44102', 'date' => '26 May 2025', 'status' => 'Cleared'],
            ['id' => 'PAY-9014', 'shop' => 'City Gadgets', 'invoice' => 'INV-2505-410', 'amount' => 75000, 'method' => 'bKash', 'ref' => 'BK-229100', 'date' => '24 May 2025', 'status' => 'Pending'],
            ['id' => 'PAY-9009', 'shop' => 'Smart Plaza', 'invoice' => 'INV-2505-398', 'amount' => 40000, 'method' => 'Cash', 'ref' => 'COL-SM-204', 'date' => '20 May 2025', 'status' => 'Cleared'],
        ];
    }

    public static function returns(): array
    {
        return [
            ['no' => 'RTN-2505-031', 'shop' => 'Tech Zone', 'order' => 'ORD-2505-1190', 'items' => 4, 'amount' => 5000, 'reason' => 'Defective unit', 'status' => 'Under Review'],
            ['no' => 'RTN-2505-028', 'shop' => 'Mobile World', 'order' => 'ORD-2505-1172', 'items' => 2, 'amount' => 2500, 'reason' => 'Wrong SKU dispatched', 'status' => 'Approved'],
            ['no' => 'RTN-2505-022', 'shop' => 'Electro Mart', 'order' => 'ORD-2505-1144', 'items' => 1, 'amount' => 4200, 'reason' => 'Warranty replacement', 'status' => 'Completed'],
        ];
    }

    public static function suppliers(): array
    {
        return [
            ['id' => 'SUP-CN-01', 'name' => 'Shenzhen Apex Electronics', 'contact' => 'Li Wei', 'terms' => 'T/T 30%', 'open_pos' => 3, 'status' => 'Preferred'],
            ['id' => 'SUP-CN-02', 'name' => 'Guangzhou Nova Trading', 'contact' => 'Chen Mei', 'terms' => 'FOB Shenzhen', 'open_pos' => 1, 'status' => 'Active'],
            ['id' => 'SUP-CN-03', 'name' => 'Yiwu Global Gadgets Co.', 'contact' => 'Zhang Hao', 'terms' => 'L/C at sight', 'open_pos' => 2, 'status' => 'Active'],
        ];
    }

    public static function purchases(): array
    {
        return [
            ['no' => 'PO-2505-014', 'supplier' => 'Shenzhen Apex Electronics', 'shipment' => 'SH-2505-001', 'amount' => 1864000, 'items' => 1840, 'status' => 'In Transit'],
            ['no' => 'PO-2505-011', 'supplier' => 'Guangzhou Nova Trading', 'shipment' => 'SH-2505-002', 'amount' => 942000, 'items' => 960, 'status' => 'Customs'],
            ['no' => 'PO-2504-088', 'supplier' => 'Yiwu Global Gadgets Co.', 'shipment' => 'SH-2504-018', 'amount' => 2215000, 'items' => 2210, 'status' => 'Received'],
        ];
    }

    public static function warehouses(): array
    {
        return [
            ['id' => 'WH-DHK-01', 'name' => 'Dhaka Central Warehouse', 'location' => 'Tejgaon, Dhaka', 'skus' => 842, 'capacity' => '92%', 'manager' => 'Abdul Malek', 'status' => 'Operational'],
            ['id' => 'WH-CTG-01', 'name' => 'Chattogram Bonded Store', 'location' => 'CEPZ, Chattogram', 'skus' => 310, 'capacity' => '61%', 'manager' => 'Jamal Uddin', 'status' => 'Operational'],
            ['id' => 'WH-KHL-01', 'name' => 'Khulna Distribution Hub', 'location' => 'Khalishpur, Khulna', 'skus' => 186, 'capacity' => '44%', 'manager' => 'Rina Akter', 'status' => 'Operational'],
        ];
    }

    public static function categories(): array
    {
        return [
            ['name' => 'Audio', 'skus' => 126, 'products' => 48],
            ['name' => 'Power', 'skus' => 84, 'products' => 22],
            ['name' => 'Wearables', 'skus' => 61, 'products' => 18],
            ['name' => 'Accessories', 'skus' => 310, 'products' => 96],
            ['name' => 'Mobile Devices', 'skus' => 42, 'products' => 14],
        ];
    }

    public static function users(): array
    {
        return [
            ['name' => 'Super Admin', 'email' => 'admin@maksinternational.com', 'role' => 'Super Admin', 'scope' => 'All warehouses', 'status' => 'Active'],
            ['name' => 'Warehouse Manager', 'email' => 'warehouse@bynnas.trade', 'role' => 'Warehouse', 'scope' => 'Dhaka Central', 'status' => 'Active'],
            ['name' => 'Karim Uddin', 'email' => 'karim@bynnas.trade', 'role' => 'Salesman', 'scope' => 'Dhaka North', 'status' => 'Active'],
            ['name' => 'Rafiq Hasan', 'email' => 'techzone@partner.local', 'role' => 'Shop Owner', 'scope' => 'Tech Zone', 'status' => 'Active'],
            ['name' => 'Accounts Officer', 'email' => 'accounts@bynnas.trade', 'role' => 'Finance', 'scope' => 'Payments & credit', 'status' => 'Active'],
        ];
    }

    public static function activities(): array
    {
        return [
            ['icon' => 'order', 'text' => 'New order ORD-2505-1289 created by Tech Zone', 'time' => '2 minutes ago'],
            ['icon' => 'approve', 'text' => 'Order ORD-2505-1288 approved for Gadget Hub', 'time' => '18 minutes ago'],
            ['icon' => 'ship', 'text' => 'Shipment SH-2505-002 moved to customs clearance', 'time' => '1 hour ago'],
            ['icon' => 'pay', 'text' => 'Payment PAY-9018 of ৳ 1,20,000 received from Tech Zone', 'time' => '3 hours ago'],
            ['icon' => 'user', 'text' => 'Shop City Gadgets submitted for Super Admin review', 'time' => '5 hours ago'],
        ];
    }
}
