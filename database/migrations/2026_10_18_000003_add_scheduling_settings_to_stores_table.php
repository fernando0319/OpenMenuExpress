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
            $table->unsignedSmallInteger('scheduling_lead_minutes')->default(60)->after('accepts_scheduling');
            $table->unsignedTinyInteger('scheduling_days_ahead')->default(2)->after('scheduling_lead_minutes');
            $table->unsignedSmallInteger('scheduling_slot_minutes')->default(30)->after('scheduling_days_ahead');
            $table->unsignedSmallInteger('scheduling_slot_capacity')->nullable()->after('scheduling_slot_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'scheduling_lead_minutes',
                'scheduling_days_ahead',
                'scheduling_slot_minutes',
                'scheduling_slot_capacity',
            ]);
        });
    }
};
