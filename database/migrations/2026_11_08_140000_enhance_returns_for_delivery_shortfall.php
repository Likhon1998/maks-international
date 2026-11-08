<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (! Schema::hasColumn('returns', 'delivery_id')) {
                $table->foreignId('delivery_id')->nullable()->after('order_id')->constrained('deliveries')->nullOnDelete();
            }
            if (! Schema::hasColumn('returns', 'restocked_at')) {
                $table->timestamp('restocked_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('returns', 'restocked_by')) {
                $table->foreignId('restocked_by')->nullable()->after('restocked_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'restocked_by')) {
                $table->dropConstrainedForeignId('restocked_by');
            }
            if (Schema::hasColumn('returns', 'restocked_at')) {
                $table->dropColumn('restocked_at');
            }
            if (Schema::hasColumn('returns', 'delivery_id')) {
                $table->dropConstrainedForeignId('delivery_id');
            }
        });
    }
};
