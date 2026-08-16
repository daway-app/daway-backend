<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The code (profile form + dashboard) saves/reads day_of_week,
     * open_time, close_time and is_closed, but the original table only
     * had day / opening_time / closing_time. This migration adds the
     * expected columns and backfills them from the old ones.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('pharmacy_hours', 'day_of_week')) {
            Schema::table('pharmacy_hours', function (Blueprint $table) {
                // day becomes nullable: new rows created by the profile form
                // only fill day_of_week (the old column is kept, not dropped).
                $table->enum('day', ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'])->nullable()->change();

                $table->string('day_of_week', 20)->nullable()->after('day');
                $table->time('open_time')->nullable()->after('opening_time');
                $table->time('close_time')->nullable()->after('closing_time');
                $table->boolean('is_closed')->default(false)->after('close_time');

                $table->index(['pharmacy_id', 'day_of_week'], 'pharmacy_hours_pharmacy_day_of_week_index');
            });
        }

        DB::table('pharmacy_hours')->update([
            'day_of_week' => DB::raw("CASE day
                WHEN 'sat' THEN 'Saturday'
                WHEN 'sun' THEN 'Sunday'
                WHEN 'mon' THEN 'Monday'
                WHEN 'tue' THEN 'Tuesday'
                WHEN 'wed' THEN 'Wednesday'
                WHEN 'thu' THEN 'Thursday'
                WHEN 'fri' THEN 'Friday'
                ELSE day END"),
            'open_time' => DB::raw('opening_time'),
            'close_time' => DB::raw('closing_time'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pharmacy_hours', 'day_of_week')) {
            Schema::table('pharmacy_hours', function (Blueprint $table) {
                $table->dropIndex('pharmacy_hours_pharmacy_day_of_week_index');
                $table->dropColumn(['day_of_week', 'open_time', 'close_time', 'is_closed']);
            });
        }
    }
};
