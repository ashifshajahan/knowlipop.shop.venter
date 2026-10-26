<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 40)->default('razorpay_route');
            $table->string('provider_account_id', 120)->nullable()->unique();
            $table->string('onboarding_status', 40)->default('not_started');
            $table->string('kyc_status', 40)->default('pending');
            $table->boolean('payout_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_id')->nullable()->index();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discounts_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('payment_status', 30)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('razorpay');
            $table->string('provider_payment_id', 120)->nullable()->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('INR');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->decimal('rate_percent', 5, 2);
            $table->unsignedBigInteger('basis_amount_minor');
            $table->unsignedBigInteger('commission_amount_minor');
            $table->unsignedBigInteger('seller_amount_minor');
            $table->char('currency', 3)->default('INR');
            $table->string('policy_version', 50);
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('transfers_or_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider_transfer_id', 120)->nullable()->unique();
            $table->string('seller_account_id', 120)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 160)->unique();
            $table->string('event_type', 100);
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider_refund_id', 120)->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 30)->default('pending')->index();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('transfers_or_settlements');
        Schema::dropIfExists('commission_records');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('seller_payment_accounts');
    }
};
