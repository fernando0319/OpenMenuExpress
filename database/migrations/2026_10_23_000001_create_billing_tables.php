<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans, each store's subscription and the vouchers that extend it.
     * Payments happen outside the system; the admin releases access or hands out vouchers.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('price_cents')->default(0); // reference price, shown to merchants
            $table->string('interval', 10)->default('month'); // month | year
            $table->json('modules');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('theme')->constrained()->nullOnDelete();
            $table->timestamp('trial_ends_at')->nullable()->after('plan_id');
            $table->timestamp('paid_until')->nullable()->after('trial_ends_at');
            $table->boolean('is_complimentary')->default(false)->after('paid_until');
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days');
            $table->unsignedInteger('max_redemptions')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('days');
            $table->timestamp('paid_until_before')->nullable();
            $table->timestamp('paid_until_after');
            $table->timestamp('created_at')->nullable();

            $table->unique(['voucher_id', 'store_id']);
        });

        // Stores that already exist start a trial now, so turning billing on never locks them at once.
        DB::table('stores')->whereNull('trial_ends_at')->update([
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 14)),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['trial_ends_at', 'paid_until', 'is_complimentary']);
        });

        Schema::dropIfExists('plans');
    }
};
