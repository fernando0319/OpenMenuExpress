<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store branding on the customer menu: the gradient's second color and the theme in use.
     * Logo, cover and the primary color already exist on stores.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('accent_color', 7)->nullable()->after('primary_color');
            $table->string('theme', 40)->default('default')->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['accent_color', 'theme']);
        });
    }
};
