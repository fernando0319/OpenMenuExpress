<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Joined tables: a table pulled into another table's open tab (a big group across tables 3 and 4).
     */
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->foreignId('joined_tab_id')->nullable()->after('is_active')->constrained('tabs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('joined_tab_id');
        });
    }
};
