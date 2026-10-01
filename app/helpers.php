<?php

/**
 * دوال مساعدة عامة (Global helpers).
 *
 * ⚠️ لماذا لا نُسجّلها في `composer.json` ضمن `autoload.files`؟
 * لأن ذلك يفرض `composer dump-autoload` على كل بيئة، ويجعل أي انحراف في
 * الـautoloader سببًا لخطأ 500 غامض. التحميل من `bootstrap/app.php` مضمون
 * على كل بيئة بلا خطوة إضافية، والدوال نقية (بلا حالة) فلا ضرر من تعريفها.
 */

use App\Services\Accounting\AccountingReports;

if (! function_exists('account_money')) {
    /**
     * تنسيق مبلغ بالشيكل للعرض في شاشات المحاسبة.
     *
     * المصدر الوحيد للتنسيق هو `AccountingReports::money` — حتى لا يختلف
     * شكل المبلغ بين الـBlade والـAPI لنفس الفاتورة.
     */
    function account_money(float|int|string|null $amount): string
    {
        return AccountingReports::money((float) ($amount ?? 0));
    }
}
