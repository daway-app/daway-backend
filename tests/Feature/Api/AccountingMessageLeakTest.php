<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Lang;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * حارس مفاتيح ترجمة المحاسبة — **من جهة الـAPI**.
 *
 * ── لماذا بديل جديد؟ ─────────────────────────────────────────────
 * `tests/Feature/Web/LangLeakStrictTest.php` يفحص HTML صفحات Blade ويموت
 * معها. لكن خطر التسريب الحقيقي للـAPI هو أن يُرجع حقلًا نصيًّا = المفتاح
 * الخام (`accounting.overview.alert_low_stock`) لأن الترجمة ناقصة في لغة
 * ما. هذا يحدث فعلًا: `AccountingReports` ينادي ثلاثة مفاتيح كانت في `ar`
 * وحدها ⇒ العميل الإنجليزي يستلم المفتاح الخام.
 *
 * الاختبار لا يعتمد على Blade ولا على قاعدة البيانات: يقرأ كود التطبيق،
 * يستخرج كل مفتاح `accounting.*` حرفي، ويتأكد أن له ترجمة في `ar` و`en`.
 */
final class AccountingMessageLeakTest extends TestCase
{
    /** @return list<string> */
    private function accountingKeysUsedInApp(): array
    {
        $root = base_path('app');
        $keys = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            // `__('accounting.x')` و`trans('accounting.x')` و`Lang::get('accounting.x')`
            // بمفتاح حرفي فقط — المفاتيح الديناميكية (سلسلة + متغيّر) يغطيها
            // الاختبار الثاني عبر فحص النطاقات كاملة.
            if (preg_match_all(
                '/\b(?:__|trans)\(\s*[\'"](accounting\.[A-Za-z0-9_.]+)[\'"]/',
                $code,
                $m
            )) {
                foreach ($m[1] as $key) {
                    // مفتاح ينتهي بنقطة = بادئة دمج ديناميكي
                    // (`__('accounting.statuses.'.$status)`) لا مفتاحًا فعليًّا.
                    // النطاقات الديناميكية يفحصها الاختبار الثاني كاملةً.
                    if (str_ends_with($key, '.')) {
                        continue;
                    }

                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    public function test_every_accounting_key_used_by_the_api_resolves_in_both_locales(): void
    {
        $keys = $this->accountingKeysUsedInApp();

        $this->assertNotEmpty($keys, 'لم يُعثر على أي مفتاح accounting.* في app/ — تغيّر نمط الاستخدام؟');

        $missing = [];

        foreach ($keys as $key) {
            foreach (['ar', 'en'] as $locale) {
                if (! Lang::hasForLocale($key, $locale)) {
                    $missing[] = $locale.' => '.$key;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "مفاتيح محاسبة بلا ترجمة (يصل العميل نصًّا خامًّا):\n".implode("\n", $missing)
        );
    }

    public function test_dynamic_accounting_namespaces_are_complete_in_both_locales(): void
    {
        // هذه النطاقات تُبنى بالدمج: __('accounting.payment_methods.'.$method)
        // فلا يراها الفحص الحرفي — نقارن مفاتيحها بين اللغتين مباشرة.
        foreach (['accounting.payment_methods', 'accounting.statuses', 'accounting.expenses.category'] as $prefix) {
            $ar = Lang::get($prefix, [], 'ar');
            $en = Lang::get($prefix, [], 'en');

            $this->assertIsArray($ar, $prefix.' يجب أن يكون مصفوفة في ar');
            $this->assertIsArray($en, $prefix.' يجب أن يكون مصفوفة في en');

            $this->assertSame(
                [],
                array_values(array_diff(array_keys($ar), array_keys($en))),
                $prefix.' — مفاتيح موجودة في ar وغائبة في en'
            );
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($en), array_keys($ar))),
                $prefix.' — مفاتيح موجودة في en وغائبة في ar'
            );
        }
    }
}
