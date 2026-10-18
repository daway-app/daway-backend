<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rider Delivery — دور «المندوب» في نظام المستخدمين الحالي.
 *
 * قرار معتمد: إعادة استخدام جدول `users` بدل إنشاء جدول `drivers` مستقل،
 * فالمندوب حساب كامل يستفيد من Sanctum و`is_active` وSpatie Permission بلا
 * نظام هوية موازٍ. هذا الامتداد وحده لا يغيّر سلوك أي دور قائم.
 *
 * ── سلوك rollback (مقصود وموثّق) ────────────────────────────────────────────
 * `up()` توسّع الـenum، و`down()` تُضيِّقه. تقليص الـenum إلى ثلاث قيم بينما
 * توجد حسابات `role = 'rider'` إمّا **يفشل** في MySQL (strict mode) أو
 * **يقتطع** القيمة إلى '' (non-strict) — أي فقدان دور مستخدم قائم. لذلك:
 *   · إذا وُجد أي حساب `role = 'rider'` → `down()` **لا تفعل شيئاً (no-op)**
 *     وتُبقي السكيمة كما هي حفاظاً على بيانات المستخدمين (سلوك متعمّد).
 *   · إذا لم يوجد أي مندوب → `down()` تُرجع الـenum إلى قيمه الثلاث السابقة.
 * لتنفيذ تراجع كامل يدوياً: أعِد أدوار المندوبين (أو احذف حساباتهم) ثم أعد
 * تشغيل هذا الـ`down()` — لا تُشغَّل أي migration إنتاجية تلقائياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['patient', 'pharmacy', 'admin', 'rider'])
                ->default('patient')
                ->change();
        });
    }

    public function down(): void
    {
        // لا تُضيِّق الـenum وفيه حسابات مندوبين: التقليص يفشل (strict) أو يقتطع
        // الدور إلى '' (non-strict) ⇒ فقدان بيانات. نكتفي بالتوقف (no-op).
        if (DB::table('users')->where('role', 'rider')->exists()) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['patient', 'pharmacy', 'admin'])
                ->default('patient')
                ->change();
        });
    }
};
