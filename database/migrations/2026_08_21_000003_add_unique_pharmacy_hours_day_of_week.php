<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * يمنع تكرار نفس اليوم لنفس الصيدلية عبر day_of_week
     * (بدل اليوم القديم day). اليوم القديم أصبح nullable بعد إضافة day_of_week،
     * و day_of_week nullable فيبقى يسمح بأكثر من سطر NULL لنفس الصيدلية.
     */
    public function up(): void
    {
        Schema::table('pharmacy_hours', function (Blueprint $table) {
            $table->dropUnique('unique_pharmacy_day');
            $table->unique(['pharmacy_id', 'day_of_week'], 'pharmacy_hours_day_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_hours', function (Blueprint $table) {
            $table->dropUnique('pharmacy_hours_day_unique');
            $table->unique(['pharmacy_id', 'day'], 'unique_pharmacy_day');
        });
    }
};