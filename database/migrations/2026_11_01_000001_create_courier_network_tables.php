<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's courier network: couriers registered with the platform (not with a store),
     * the runs stores call them for, what each store owes for its runs (daily or weekly bills)
     * and the weekly payouts to couriers. Money moves outside the system (PIX); here it is recorded.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('network_enabled')->default(false)->after('max_delivery_radius_km');
            $table->unsignedInteger('network_fee_cents')->nullable()->after('network_enabled');
            $table->string('network_billing_cycle', 10)->default('weekly')->after('network_fee_cents'); // daily | weekly
        });

        Schema::create('network_couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('cpf', 11)->unique();
            $table->string('phone', 20);
            $table->string('city', 80);
            $table->char('state', 2);
            $table->string('vehicle', 10); // motorcycle | bicycle | car
            $table->string('vehicle_plate', 10)->nullable();
            $table->string('pix_key_type', 10); // cpf | phone | email | random
            $table->string('pix_key', 140);
            $table->string('document_path')->nullable(); // private disk
            $table->string('status', 10)->default('pending'); // pending | approved | rejected | blocked
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_online')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('located_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_online', 'located_at']);
        });

        Schema::create('delivery_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('cycle', 10); // daily | weekly
            $table->date('period_start'); // store timezone
            $table->date('period_end'); // inclusive
            $table->unsignedInteger('deliveries_count');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('due_at');
            $table->string('status', 10)->default('open'); // open | paid | waived
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status', 'due_at']);
            $table->index(['status', 'period_start']);
        });

        Schema::create('courier_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_courier_id')->constrained()->cascadeOnDelete();
            $table->date('period_start'); // Monday, platform timezone
            $table->date('period_end'); // Sunday
            $table->unsignedInteger('deliveries_count');
            $table->unsignedBigInteger('amount_cents');
            $table->string('pix_key_type', 10);
            $table->string('pix_key', 140);
            $table->string('status', 10)->default('pending'); // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        Schema::create('network_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12); // searching | accepted | picked_up | delivered | cancelled
            $table->unsignedInteger('fee_cents'); // what the store pays
            $table->unsignedSmallInteger('platform_fee_bps'); // 1500 = 15%
            $table->unsignedInteger('courier_cents'); // what the courier receives
            $table->decimal('pickup_latitude', 10, 7);
            $table->decimal('pickup_longitude', 10, 7);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('searching_since');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('delivery_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'searching_since']);
            $table->index(['network_courier_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // Couriers who said no to a run (or gave it up) are not offered it again.
        Schema::create('network_delivery_declines', function (Blueprint $table) {
            $table->foreignId('network_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_courier_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['network_delivery_id', 'network_courier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_delivery_declines');
        Schema::dropIfExists('network_deliveries');
        Schema::dropIfExists('courier_payouts');
        Schema::dropIfExists('delivery_bills');
        Schema::dropIfExists('network_couriers');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['network_enabled', 'network_fee_cents', 'network_billing_cycle']);
        });

        Schema::dropIfExists('platform_settings');
    }
};
