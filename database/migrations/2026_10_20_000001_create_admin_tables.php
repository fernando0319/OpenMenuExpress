<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform administration: suspending a store and the log of every admin action.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_paused');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
