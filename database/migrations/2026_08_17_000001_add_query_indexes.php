<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            // يدعم استعلامات نقص المخزون العامة (حسب الصيدلية والكمية)
            $table->index(['pharmacy_id', 'quantity'], 'pharmacy_medicines_pharmacy_quantity_index');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            // سجل الأنشطة يُرتب الأحدث أولاً (latest)
            $table->index('created_at', 'activity_log_created_at_index');
        });

        Schema::table('reminders', function (Blueprint $table) {
            // استعلامات التذكيرات حسب التاريخ
            $table->index('reminder_date', 'reminders_reminder_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex('reminders_reminder_date_index');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_created_at_index');
        });

        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->dropIndex('pharmacy_medicines_pharmacy_quantity_index');
        });
    }
};
