<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider Delivery — هوية المنفّذ في سجل تغيّر الحالات.
 *
 * `order_status_histories` كان يسجّل الحالة والوقت فقط. مع دخول أكثر من
 * فاعل (صيدلية · مندوب · أدمن · مريض) على نفس الطلب، يصبح «من نفّذ الانتقال»
 * جزءاً من التدقيق. عمودان فقط، بلا كسر الصفوف التاريخية (NULL = غير معروف).
 *
 * ملاحظة: القيد المركّب (sub_order_id, order_id) → sub_orders أُضيف في
 * Phase 1.1 عبر إعادة بناء الجدول؛ إضافة أعمدة عبر ALTER لا تمسّه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('order_status_histories', 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable()->after('note');
                $table->index('actor_user_id', 'order_status_histories_actor_user_id_index');
            }
            if (! Schema::hasColumn('order_status_histories', 'actor_role')) {
                $table->string('actor_role')->nullable()->after('actor_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            if (Schema::hasColumn('order_status_histories', 'actor_user_id')) {
                $table->dropIndex('order_status_histories_actor_user_id_index');
                $table->dropColumn('actor_user_id');
            }
            if (Schema::hasColumn('order_status_histories', 'actor_role')) {
                $table->dropColumn('actor_role');
            }
        });
    }
};
