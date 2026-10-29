<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('description')->nullable();
            $table->string('type'); // percent | fixed | free_delivery
            $table->unsignedSmallInteger('percent')->nullable(); // 1–100
            $table->unsignedInteger('amount_cents')->nullable(); // fixed discount
            $table->unsignedInteger('max_discount_cents')->nullable(); // cap for percent
            $table->unsignedInteger('min_subtotal_cents')->default(0);
            $table->unsignedInteger('usage_limit')->nullable(); // total uses
            $table->unsignedSmallInteger('per_customer_limit')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->string('fulfillment')->nullable(); // null = any | delivery | pickup
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_cents');
            $table->timestamps();

            $table->index(['coupon_id', 'customer_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('delivery_distance_km')->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable()->after('coupon_id');
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('image_path');
            $table->string('link_type')->default('none'); // none | product | category | coupon | url
            $table->string('link_target')->nullable(); // id, coupon code or URL
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_code');
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
