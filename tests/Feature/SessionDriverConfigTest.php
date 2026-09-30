<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 2 — حارس قرار Session Driver.
 *
 * السبب: `render.yaml` لم يكن يضبط SESSION_DRIVER ⇒ الإنتاج كان يورث الافتراضي
 * في config/session.php وهو `database` ⇒ قراءة + كتابة على جدول sessions في
 * Aiven (شبكة عامة) في كل طلب ويب.
 *
 * هذا الاختبار يمنع الانحدار الصامت: لو أعاد أحدهم الافتراضي إلى `database`
 * بلا ضبط صريح في render.yaml، يسقط الاختبار فورًا.
 */
class SessionDriverConfigTest extends TestCase
{
    public function test_config_default_session_driver_is_file_not_database(): void
    {
        // نقرأ **نص** ملف الإعداد لا قيمته المُقيَّمة، لأن env() تُحلّ وقت
        // التقييم وphpunit.xml تضبط SESSION_DRIVER=array عمدًا للعزل.
        // المطلوب هنا هو حرفياً القيمة الاحتياطية المكتوبة في الملف.
        $source = file_get_contents(base_path('config/session.php'));

        $this->assertMatchesRegularExpression(
            "/'driver'\s*=>\s*env\(\s*'SESSION_DRIVER'\s*,\s*'file'\s*\)/",
            $source,
            'الافتراضي الاحتياطي في config/session.php يجب أن يكون file. '
                .'القيمة database تعني أن أي بيئة لا تضبط SESSION_DRIVER صراحةً '
                .'ستقرأ وتكتب جدول sessions على القاعدة في كل طلب.'
        );

        $this->assertStringNotContainsString(
            "'SESSION_DRIVER', 'database'",
            $source,
            'لا يجوز أن يعود الافتراضي الاحتياطي إلى database.'
        );
    }

    public function test_render_yaml_pins_session_driver_to_file(): void
    {
        $render = file_get_contents(base_path('render.yaml'));

        $this->assertStringContainsString(
            'SESSION_DRIVER',
            $render,
            'render.yaml يجب أن يضبط SESSION_DRIVER صراحةً — لا يجوز الاعتماد على الافتراضي.'
        );

        $this->assertMatchesRegularExpression(
            '/- key: SESSION_DRIVER\s*\n\s*value: file/',
            $render,
            'render.yaml يجب أن يثبّت SESSION_DRIVER=file.'
        );
    }

    public function test_dockerfile_creates_session_storage_directory(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString(
            'storage/framework/sessions',
            $dockerfile,
            'مشغّل جلسات file يحتاج storage/framework/sessions موجودًا ومملوكًا '
                .'للمستخدم غير الجذر — هذه إحدى الشروط المسبقة لتشغيل file بأمان.'
        );
    }

    public function test_session_store_roundtrips_a_value(): void
    {
        // تأكيد سلوكي: الجلسة تُحلّ فعلاً في هذه البيئة وتُخزّن قيمة وتعيدها
        // (يعني أن نوع المشغّل المضبوط مدعوم في هذه النسخة من Laravel).
        $session = app('session.store');
        $session->start();
        $session->put('phase2_probe', 'ok');

        $this->assertSame('ok', $session->get('phase2_probe'));

        $session->forget('phase2_probe');
    }
}
