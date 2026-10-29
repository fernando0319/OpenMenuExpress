<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dine-in: tables, the tab (comanda) opened on a table, and the rounds (orders) sent from it.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedTinyInteger('service_fee_percent')->default(10)->after('auto_print');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('seats')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        Schema::create('tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20); // open | closing | closed | cancelled
            $table->string('customer_name', 80)->nullable();
            $table->unsignedSmallInteger('people')->nullable();
            $table->unsignedInteger('subtotal_cents')->default(0); // snapshots at closing
            $table->unsignedInteger('service_fee_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('bill_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['dining_table_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('tab_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('tab_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tab_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tab_id');
        });

        Schema::dropIfExists('tabs');
        Schema::dropIfExists('dining_tables');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('service_fee_percent');
        });
    }
};
