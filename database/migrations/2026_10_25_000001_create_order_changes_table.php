<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each edit of an order after it was placed: who, when, why, and what changed
     * (items added/removed and other fields), with the totals before and after.
     */
    public function up(): void
    {
        Schema::create('order_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->json('items');
            $table->json('fields');
            $table->integer('total_before_cents');
            $table->integer('total_after_cents');
            $table->integer('refunded_cents')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_changes');
    }
};
