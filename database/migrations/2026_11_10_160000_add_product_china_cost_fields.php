<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'china_purchase_price')) {
                $table->decimal('china_purchase_price', 14, 2)->default(0)->after('cost_price');
            }
            if (! Schema::hasColumn('products', 'shipping_cost')) {
                $table->decimal('shipping_cost', 14, 2)->default(0)->after('china_purchase_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['shipping_cost', 'china_purchase_price'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
