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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('America/Sao_Paulo');

            $table->string('address_zip', 9)->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('address_district')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_complement')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedInteger('minimum_order_cents')->default(0);
            $table->boolean('is_paused')->default(false);
            $table->boolean('accepts_delivery')->default(true);
            $table->boolean('accepts_pickup')->default(true);
            $table->boolean('accepts_dine_in')->default(false);
            $table->boolean('accepts_scheduling')->default(false);

            $table->string('delivery_fee_mode')->default('zone');
            $table->unsignedInteger('distance_fee_per_km_cents')->default(0);
            $table->decimal('free_delivery_radius_km', 6, 2)->default(0);
            $table->decimal('max_delivery_radius_km', 6, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
        });

        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = domingo … 6 = sábado
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->index(['store_id', 'weekday']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('fee_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('opening_hours');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
