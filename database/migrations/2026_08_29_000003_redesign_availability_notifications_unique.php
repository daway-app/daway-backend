<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H5: إعادة تصميم قيد availability_notifications.
     *
     * المشكلة: القيد unique(['user_id', 'medicine_id', 'pharmacy_id']) يمنع
     * إشعاراً ثانياً لنفس الـ tuple بعد إعادة توفر الدواء — المستخدم يفقد
     * تنبيهاً حيوياً.
     *
     * الحل:
     *  1) أزل الـ unique القديم.
     *  2) أضف عمود notified_at timestamp (nullable) يحدّث عند التسليم.
     *  3) أضف unique مركّب يشمل notified_at ليسمح بتكرار الإشعار بعد
     *     إعادة التوفر (الـ timestamp يختلف في كل دورة إشعار).
     */
    public function up(): void
    {
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->dropUnique('unique_availability_notification');
            $table->timestamp('notified_at')->nullable()->after('is_notified');
            $table->unique(
                ['user_id', 'medicine_id', 'pharmacy_id', 'notified_at'],
                'unique_availability_notification_v2'
            );
        });
    }

    public function down(): void
    {
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->dropUnique('unique_availability_notification_v2');
            $table->dropColumn('notified_at');
            $table->unique(
                ['user_id', 'medicine_id', 'pharmacy_id'],
                'unique_availability_notification'
            );
        });
    }
};
