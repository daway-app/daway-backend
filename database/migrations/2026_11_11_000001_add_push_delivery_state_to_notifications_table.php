<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حالة تسليم الـpush لكل إشعار.
 *
 * سبب الوجود: `FcmPushService` كان يسجّل الفشل في ملف السجل فقط، فلا توجد
 * طريقة لمعرفة أي إشعار لم يصل، ولا لإعادة محاولته. هذه الأعمدة تجعل
 * الإشعار **قابلًا لإعادة المحاولة** دون الحاجة إلى Worker دائم.
 *
 * كلها nullable/default — إضافية بالكامل، لا تمسّ أي صف قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // sent | failed | skipped | pending — null يعني «لم تُحاول بعد»
            $table->string('push_status', 20)->nullable()->after('is_read');
            $table->unsignedTinyInteger('push_attempts')->default(0)->after('push_status');
            // رمز خطأ FCM فقط (UNREGISTERED/UNAVAILABLE/...)، لا نص حر من المزوّد.
            $table->string('push_error_code', 40)->nullable()->after('push_attempts');
            $table->timestamp('pushed_at')->nullable()->after('push_error_code');

            $table->index(['push_status', 'push_attempts'], 'notifications_push_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_push_retry_idx');
            $table->dropColumn(['push_status', 'push_attempts', 'push_error_code', 'pushed_at']);
        });
    }
};
