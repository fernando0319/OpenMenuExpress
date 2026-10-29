<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every amount a store received or gave back, in the asset it moved (reais, bitcoin…), with
     * its value in reais at that moment. Fed by the cash register and by online payments; one row
     * per source event, so recording again never duplicates.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->string('asset', 10); // BRL, BTC
            $table->bigInteger('units')->nullable(); // signed, in the asset's smallest unit (centavos, satoshis); null when unknown
            $table->bigInteger('brl_cents'); // signed value in reais at that moment
            $table->decimal('rate', 20, 2)->nullable(); // reais per whole unit of the asset, for assets other than BRL
            $table->string('method', 30); // cash, pix, debit_card… or online_pix, online_card, online_bitcoin
            $table->string('source_type', 30); // cash_movement | online_payment
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 10); // in | out
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_type', 'source_id', 'kind']);
            $table->index(['store_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
