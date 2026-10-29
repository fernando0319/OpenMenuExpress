<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment methods each store accepts, in the order the customer sees them.
     * "method" is a string key, so payment modules added later (gateways) fit the same table.
     */
    public function up(): void
    {
        Schema::create('store_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('method', 40);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_methods');
    }
};
