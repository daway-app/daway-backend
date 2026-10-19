<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تدقيق الإرجاعات — فصل «ردّ المال» عن «إلغاء الدين» على سجل الإرجاع نفسه.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * المشكلة
 * ══════════════════════════════════════════════════════════════════════════
 * `refunds.amount` يحمل **قيمة البضاعة المرتجعة** فقط. لكن قيمة البضاعة
 * المرتجعة ليست كلها نقدًا مُردًّا: جزء منها قد يقابل دَينًا لم يُحصَّل أصلاً
 * فيُلغى بدل أن يُدفع.
 *
 * بلا هذين العمودين يستحيل على المدقّق أن يجيب: «كم نقدًا خرج فعلاً؟ وكم دَينًا
 * أُلغِي؟» من سجل الإرجاع وحده.
 *
 *     amount          = قيمة البضاعة المرتجعة  (= cash_refunded + debt_cancelled)
 *     cash_refunded   = المال الذي رُدّ فعليًا (عبر الصندوق للكاش، وعبر نفس
 *                       وسيلة الدفع الأصلية لغير الكاش)
 *     debt_cancelled  = الجزء غير المدفوع الذي أُلغِي من دين الفاتورة/العميل
 *
 * ⚠️ إضافية فقط، والافتراضي 0? لكن `amount` التاريخي محفوظ كما هو. الصفوف
 * السابقة لهذا الترحيل لا يمكن فصله فيها بأمان (معلومة مفقودة زمن إنشائها)،
 * فتُترك بصفرين بدل تخمين يمحو دقّة السجل. الإنتاج بلا صفوف إرجاع حاليًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('refunds', 'cash_refunded')) {
            Schema::table('refunds', function (Blueprint $table) {
                $table->decimal('cash_refunded', 12, 2)->default(0.00)->after('amount');
            });
        }

        if (! Schema::hasColumn('refunds', 'debt_cancelled')) {
            Schema::table('refunds', function (Blueprint $table) {
                $table->decimal('debt_cancelled', 12, 2)->default(0.00)->after('cash_refunded');
            });
        }
    }

    public function down(): void
    {
        foreach (['cash_refunded', 'debt_cancelled'] as $column) {
            if (Schema::hasColumn('refunds', $column)) {
                Schema::table('refunds', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
