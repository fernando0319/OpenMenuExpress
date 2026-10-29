<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A charge paid in another asset (a Lightning invoice in satoshis) keeps the invoice and the
     * quantity and rate it was created with: the customer pays exactly what was shown, and the
     * ledger records exactly that.
     */
    public function up(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->text('payment_request')->nullable()->after('pix_code'); // BOLT11 invoice
            $table->string('asset', 10)->nullable()->after('amount_cents');
            $table->unsignedBigInteger('asset_units')->nullable()->after('asset');
            $table->decimal('rate', 20, 2)->nullable()->after('asset_units'); // reais per whole unit
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_request', 'asset', 'asset_units', 'rate']);
        });
    }
};
