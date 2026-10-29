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
            $table->boolean('whatsapp_enabled')->default(false)->after('whatsapp');
            $table->string('whatsapp_instance')->nullable()->after('whatsapp_enabled');
        });

        // Only the templates a merchant edited are stored; the rest use the defaults in MessageEvent.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->text('body');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'event']);
        });

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('channel')->default('whatsapp');
            $table->string('recipient', 20);
            $table->text('body');
            $table->string('status'); // pending | sent | failed | skipped
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('message_templates');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_enabled', 'whatsapp_instance']);
        });
    }
};
