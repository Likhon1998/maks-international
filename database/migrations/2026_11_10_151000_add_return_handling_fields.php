<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('returns')) {
            return;
        }

        Schema::table('returns', function (Blueprint $table) {
            if (! Schema::hasColumn('returns', 'replaced')) {
                $table->boolean('replaced')->default(false)->after('credit_issued');
            }
            if (! Schema::hasColumn('returns', 'repair_status')) {
                $table->string('repair_status', 40)->default('none')->after('replaced');
            }
            if (! Schema::hasColumn('returns', 'inventory_condition')) {
                $table->string('inventory_condition', 40)->nullable()->after('repair_status');
            }
            if (! Schema::hasColumn('returns', 'refund_amount')) {
                $table->decimal('refund_amount', 14, 2)->default(0)->after('inventory_condition');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('returns')) {
            return;
        }

        Schema::table('returns', function (Blueprint $table) {
            foreach (['replaced', 'repair_status', 'inventory_condition', 'refund_amount'] as $column) {
                if (Schema::hasColumn('returns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
