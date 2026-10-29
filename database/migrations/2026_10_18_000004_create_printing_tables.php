<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // off | placed | accepted
            $table->string('auto_print')->default('accepted')->after('whatsapp_instance');
        });

        // A computer running the print agent, linked to a store with a pairing code.
        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 40)->nullable();
            $table->string('version', 20)->nullable();
            $table->json('available_printers')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // A printer the merchant chose to use, on one of the agents.
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('print_agent_id')->constrained()->cascadeOnDelete();
            $table->string('system_name');
            $table->string('label');
            $table->unsignedTinyInteger('paper_width')->default(80); // 58 | 80 mm
            $table->unsignedTinyInteger('copies')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['print_agent_id', 'system_name']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // order | test
            $table->longText('payload'); // ESC/POS bytes, base64
            $table->string('status', 20); // pending | sent | printed | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['printer_id', 'status']);
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('print_agents');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('auto_print');
        });
    }
};
