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
     *  1) أضف indexes مستقلة لأعمدة الـ FK قبل حذف الـ unique القديم؛
     *     InnoDB قد يكون يستخدمه كـ supporting index لـ user_id.
     *  2) أضف عمود notified_at timestamp (nullable).
     *  3) أزل الـ unique القديم وأضف index مركباً عادياً لدورة الإشعار.
     *     لا نستخدم unique مع nullable notified_at لأن MySQL يسمح بعدة NULLs.
     */
    public function up(): void
    {
        Schema::table('availability_notifications', function (Blueprint $table) {
            // يجب إنشاء الفهارس قبل حذف unique_availability_notification؛
            // الفهرس القديم قد يكون داعماً للـ FK على user_id.
            if (! Schema::hasIndex('availability_notifications', 'availability_notifications_user_id_index')) {
                $table->index('user_id', 'availability_notifications_user_id_index');
            }
            if (! Schema::hasIndex('availability_notifications', 'availability_notifications_medicine_id_index')) {
                $table->index('medicine_id', 'availability_notifications_medicine_id_index');
            }
            if (! Schema::hasIndex('availability_notifications', 'availability_notifications_pharmacy_id_index')) {
                $table->index('pharmacy_id', 'availability_notifications_pharmacy_id_index');
            }

            if (! Schema::hasColumn('availability_notifications', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('is_notified');
            }

            if (Schema::hasIndex('availability_notifications', 'unique_availability_notification')) {
                $table->dropUnique('unique_availability_notification');
            }
            if (! Schema::hasIndex('availability_notifications', 'availability_notifications_cycle_index')) {
                $table->index(
                    ['user_id', 'medicine_id', 'pharmacy_id', 'notified_at'],
                    'availability_notifications_cycle_index'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('availability_notifications', function (Blueprint $table) {
            if (Schema::hasIndex('availability_notifications', 'availability_notifications_cycle_index')) {
                $table->dropIndex('availability_notifications_cycle_index');
            }
            if (Schema::hasColumn('availability_notifications', 'notified_at')) {
                $table->dropColumn('notified_at');
            }
            $table->unique(
                ['user_id', 'medicine_id', 'pharmacy_id'],
                'unique_availability_notification'
            );
        });
    }
};
