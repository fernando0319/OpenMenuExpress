<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Letter size of each printer's tickets. Stores asked for bigger print, so "large" is the default.
     */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('font', 10)->default('large')->after('copies');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('font');
        });
    }
};
