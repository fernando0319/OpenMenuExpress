<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pizzeria mode. Each size is a product of kind "pizza" (Broto, Média, Grande...), so it keeps
     * category, photo, order and reports. Flavors live in groups ("Salgadas Tradicionais") that carry
     * one price per size; crusts too. Prices are JSON maps {product id: cents}: a group or crust with
     * no price for a size is not offered in that size.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 10)->default('standard')->after('category_id');
            $table->unsignedSmallInteger('pizza_slices')->nullable()->after('kind');
            $table->unsignedSmallInteger('pizza_diameter_cm')->nullable()->after('pizza_slices');
            $table->unsignedTinyInteger('pizza_max_flavors')->nullable()->after('pizza_diameter_cm');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('pizza_pricing', 10)->default('highest');
            $table->boolean('pizza_mix_kinds')->default(true);
        });

        Schema::create('pizza_flavor_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 10)->default('savory'); // savory | sweet
            $table->json('prices');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pizza_flavors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('pizza_flavor_groups')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('extra_cents')->default(0);
            $table->json('unavailable_product_ids')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pizza_crusts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            $table->json('prices');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pizza_crusts');
        Schema::dropIfExists('pizza_flavors');
        Schema::dropIfExists('pizza_flavor_groups');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['pizza_pricing', 'pizza_mix_kinds']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['kind', 'pizza_slices', 'pizza_diameter_cm', 'pizza_max_flavors']);
        });
    }
};
