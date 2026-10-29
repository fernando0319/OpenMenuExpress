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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20); // somente dígitos, com DDI (ex.: 5511999998888)
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('marketing_opt_out')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'phone']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('zip', 9)->nullable();
            $table->string('street');
            $table->string('number', 20)->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('complement')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // sequencial por loja
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();
            $table->string('channel');
            $table->string('fulfillment');
            $table->string('status');

            // Cópia do endereço no momento do pedido
            $table->string('delivery_zip', 9)->nullable();
            $table->string('delivery_street')->nullable();
            $table->string('delivery_number', 20)->nullable();
            $table->string('delivery_district')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_state', 2)->nullable();
            $table->string('delivery_complement')->nullable();
            $table->string('delivery_reference')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('delivery_distance_km', 6, 2)->nullable();

            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('total_cents');

            $table->string('payment_method');
            $table->unsignedInteger('change_for_cents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->unique(['store_id', 'number']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('unit_price_cents'); // preço base do produto no momento
            $table->unsignedInteger('options_price_cents')->default(0); // por unidade
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('total_cents');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('option_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
