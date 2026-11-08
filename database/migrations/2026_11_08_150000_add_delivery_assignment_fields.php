<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (! Schema::hasColumn('deliveries', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            }
        });

        // Existing unassigned out-for-delivery rows become pending until admin assigns.
        DB::table('deliveries')
            ->whereNull('assigned_to')
            ->where('status', 'out_for_delivery')
            ->update(['status' => 'pending']);

        // Existing assigned out-for-delivery rows keep status; stamp assigned_at when missing.
        DB::table('deliveries')
            ->whereNotNull('assigned_to')
            ->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('COALESCE(dispatched_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('deliveries', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }
        });
    }
};
