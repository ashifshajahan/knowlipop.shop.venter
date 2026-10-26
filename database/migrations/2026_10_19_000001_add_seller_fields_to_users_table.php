<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'seller_name')) $table->string('seller_name', 120)->nullable();
            if (! Schema::hasColumn('users', 'phone')) $table->string('phone', 16)->nullable()->unique();
            if (! Schema::hasColumn('users', 'address')) $table->string('address', 500)->nullable();
            if (! Schema::hasColumn('users', 'landmark')) $table->string('landmark', 200)->nullable();
            if (! Schema::hasColumn('users', 'state')) $table->string('state', 100)->nullable();
            if (! Schema::hasColumn('users', 'country')) $table->string('country', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['seller_name', 'address', 'landmark', 'state', 'country'] as $column) {
                if (Schema::hasColumn('users', $column)) $table->dropColumn($column);
            }
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropUnique(['phone']);
                $table->dropColumn('phone');
            }
        });
    }
};
