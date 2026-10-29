<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online payments straight to the store's own accounts (Mercado Pago, Sicoob Pix, BTCPay).
     * Credentials are encrypted; each account has an unguessable webhook token.
     */
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->json('methods'); // online methods this account takes (online_pix, online_card, online_bitcoin)
            $table->text('credentials'); // encrypted
            $table->string('account_label', 120)->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'provider']);
        });

        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('method', 20);
            $table->string('provider_ref', 120)->nullable();
            $table->string('status', 12)->default('pending'); // pending | paid | expired | failed | refunded
            $table->unsignedBigInteger('amount_cents');
            $table->text('pix_code')->nullable();
            $table->string('checkout_url', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_ref']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
        Schema::dropIfExists('payment_accounts');
    }
};
