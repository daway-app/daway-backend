<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider Delivery — إسناد الطلب الأب إلى مندوب واحد + طابع الاستلام.
 *
 * `orders` هو الطلب الأب (رأس الرحلة)، والإسناد قرار على الرحلة لا على
 * الصيدلية الفرعية، لذا الأعمدة هنا على الأب فقط:
 *   · rider_id          — المندوب الحالي (NULL = غير مُسند).
 *   · assigned_by       — أدمن أسند الطلب (تدقيق «من أسند»).
 *   · assigned_at       — متى تم الإسناد (تدقيق «متى»).
 *   · picked_up_at      — لحظة اكتمال استلام كل الطلبات الفرعية المطلوبة.
 *
 * ملاحظة على المفتاح الأجنبي: مثل `sub_order_id` (Phase 1) نضيف عموداً
 * مفهرساً BIGINT بدل قيد FK حقيقي — SQLite (كل الاختبارات) لا يدعم إضافة FK
 * لجدول قائم، وسياسة المشروع المعلنة هي الأعمدة المفهرسة في تعديلات ALTER.
 * الصفوف التاريخية تبقى NULL (بلا مندوب) بلا أي تغيير سلوكي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'rider_id')) {
                $table->unsignedBigInteger('rider_id')->nullable()->after('pharmacy_id');
                $table->index('rider_id', 'orders_rider_id_index');
            }
            if (! Schema::hasColumn('orders', 'assigned_by')) {
                $table->unsignedBigInteger('assigned_by')->nullable()->after('rider_id');
            }
            if (! Schema::hasColumn('orders', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            }
            if (! Schema::hasColumn('orders', 'picked_up_at')) {
                $table->timestamp('picked_up_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'rider_id')) {
                $table->dropIndex('orders_rider_id_index');
                $table->dropColumn('rider_id');
            }
            if (Schema::hasColumn('orders', 'assigned_by')) {
                $table->dropColumn('assigned_by');
            }
            if (Schema::hasColumn('orders', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }
            if (Schema::hasColumn('orders', 'picked_up_at')) {
                $table->dropColumn('picked_up_at');
            }
        });
    }
};
