<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash register: one open session per store at a time; every money in or out is a movement.
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('opening_cents');
            $table->integer('expected_cash_cents')->nullable(); // snapshot at closing
            $table->integer('counted_cash_cents')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'closed_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // sale | refund | supply | withdrawal
            $table->string('payment_method', 20)->nullable();
            $table->unsignedInteger('amount_cents'); // always positive; the type gives the direction
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['cash_session_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
