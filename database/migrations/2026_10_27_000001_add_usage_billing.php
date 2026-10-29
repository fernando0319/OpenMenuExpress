<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans charged by a percentage of the online sales of the month ("por uso"), free below
     * free_up_to_cents, with an optional fixed part, minimum and cap. Each closed month becomes
     * an invoice the platform marks as paid (payments happen outside the system).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('percent_bps')->nullable()->after('free_up_to_cents'); // 250 = 2,5%
            $table->string('percent_base', 6)->default('excess')->after('percent_bps'); // excess | total
            $table->unsignedInteger('fee_min_cents')->nullable()->after('percent_base');
            $table->unsignedInteger('fee_max_cents')->nullable()->after('fee_min_cents');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start'); // first day of the month, store timezone
            $table->unsignedBigInteger('sales_cents');
            $table->unsignedBigInteger('free_up_to_cents')->nullable();
            $table->unsignedSmallInteger('percent_bps');
            $table->string('percent_base', 6);
            $table->unsignedInteger('fixed_cents')->default(0);
            $table->unsignedInteger('fee_min_cents')->nullable();
            $table->unsignedInteger('fee_max_cents')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 10)->default('open'); // open | paid | waived
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'period_start']);
            $table->index(['status', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['percent_bps', 'percent_base', 'fee_min_cents', 'fee_max_cents']);
        });
    }
};
