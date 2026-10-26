<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('seller_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
                $table->index(['seller_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['seller_id']);
                $table->dropIndex(['seller_id', 'is_active']);
                $table->dropColumn('seller_id');
            });
        }
    }
};
