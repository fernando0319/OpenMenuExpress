<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery tracking: who takes each order, and the courier's positions while delivering.
     * Positions are kept for a few days only (see app:prune-courier-locations).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->after('delivery_zone_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('courier_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['store_id', 'user_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_locations');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_id');
        });
    }
};
