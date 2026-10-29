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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['store_id', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('promotional_price_cents')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_quantity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'category_id', 'sort_order']);
            $table->unique(['store_id', 'sku']);
        });

        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('min_choices')->default(0);
            $table->unsignedSmallInteger('max_choices')->default(1);
            $table->string('pricing_mode')->default('sum');
            $table->boolean('allows_quantity')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_quantity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('option_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_choices')->nullable(); // sobrescreve o grupo
            $table->unsignedSmallInteger('max_choices')->nullable(); // sobrescreve o grupo
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'option_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_group_product');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
