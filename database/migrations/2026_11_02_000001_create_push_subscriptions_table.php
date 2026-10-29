<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phones (browsers) that accepted notifications, and which couriers were already told
     * about each run, so nobody is notified twice.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 200);
            $table->string('auth_token', 100);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('network_delivery_pushes', function (Blueprint $table) {
            $table->foreignId('network_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_courier_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['network_delivery_id', 'network_courier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_delivery_pushes');
        Schema::dropIfExists('push_subscriptions');
    }
};
