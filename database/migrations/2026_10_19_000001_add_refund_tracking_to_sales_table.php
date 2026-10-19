<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تدقيق الإرجاعات — العمود الناقص على الفواتير: قيمة البضاعة المرتجعة.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * لماذا عمود جديد؟
 * ══════════════════════════════════════════════════════════════════════════
 * الفاتورة كانت تمثّل الإرجاع بعدّ `paid` ناقصًا و`remaining = total − paid`
 * فقط. هذا يجعل الفاتورة المرجوعة تعود لتظهر **كذمّة قائمة** (remaining يرتفع
 * بعد الإرجاع!)، ولا يوجد مكان يخزّن **قيمة البضاعة المرتجعة** بشكل صريح.
 *
 * الميزانية الصحيحة التي نعتمدها بعد هذا الترحيل:
 *
 *     net_total  = total − refunded_amount
 *     remaining  = net_total − paid
 *     status     = 'refunded'  عندما  refunded_amount ≥ total
 *
 * - `total`           : القيمة الإجمالية للفاتورة قبل الإرجاع (تاريخية، لا تتغيّر)
 * - `refunded_amount` : مجموع قيمة البضاعة المرتجعة (مسار البضاعة/المخزون)
 * - `paid`            : صافة النقد المحتفظ به (ينقص عند ردّ المال فعليًا)
 * - `remaining`       : الدين القائم فعلاً على العميل
 *
 * الفصل مقصود: **قيمة البضاعة المرتجعة ليست بالضرورة مبلغًا نقديًا رُدّ.**
 * بضاعة غير مدفوعة تُرجَع تُلغي دينًا بلا حركة نقدية.
 *
 * ⚠️ الهجرة **إضافية فقط** (additive): لا حذف، لا تغيير نوع، لا إسقاط بيانات.
 * القيمة الافتراضية 0 صحيحة لكل فاتورة بلا إرجاعات (ومنها فواتير الإنتاج).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'refunded_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                // قيمة البضاعة المرتجعة (لا المبلغ النقدي المُردّ).
                $table->decimal('refunded_amount', 12, 2)->default(0.00)->after('remaining');
            });
        }

        // تعبئة متوافقة للبيانات القديمة: من مجموع الإرجاعات الفعلية لكل فاتورة.
        // idempotent — إعادة التشغيل تُعيد نفس القيمة (مجموع ثابت).
        // ملاحظة: الإنتاج لا يحوي صفوف `refunds` حاليًا، فهذا التعبئة no-op هناك.
        if (Schema::hasTable('refunds')) {
            DB::table('sales')->update([
                'refunded_amount' => DB::raw(
                    '(SELECT COALESCE(SUM(r.amount), 0) FROM refunds r WHERE r.sale_id = sales.id)'
                ),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'refunded_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('refunded_amount');
            });
        }
    }
};
