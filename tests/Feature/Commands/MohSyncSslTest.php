<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\MohSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M-3B.1 — قرار تحقّق SSL لطلبات وزارة الصحة.
 *
 * العقود:
 *   1. **مُفعَّل افتراضيًا**: غياب الإعداد أو قيمته غير الصالحة ⇒ التحقق مُفعَّل
 *      (fail-secure) — لا تعطيل صامت أبدًا.
 *   2. **التعطيل صريح فقط**: 'false' / '0' / 'off' / 'no'.
 *   3. **يُقرأ عبر `config()`** لا `env()` مباشرة — لأن `config:cache` يجعل
 *      `env()` غير موثوق وقت التشغيل (`LoadEnvironmentVariables.php:22-24`
 *      يعيد مبكرًا عندما يكون الإعداد مخزّنًا).
 *   4. **عميل HTTP واحد** (`mohHttpClient`) = نقطة القرار الوحيدة التي قد
 *      تنادي `withoutVerifying()`.
 *
 * ⚠️ **حدود ما تُثبته هذه الاختبارات:** mocking لواجهة `Http` يُثبت **أيّ
 * استدعاءات** تُجرى على العميل (هل نادى `withoutVerifying()` أم لا) — لكنه
 * **لا يثبت التحقق الفعلي من سلسلة CA** ضد الخادم الحقيقي. ذلك فحص نشر
 * منفصل: نداء حقيقي لـ`pharmacy.moh.ps` بعد ضبط `MOH_SSL_VERIFY=true`.
 *
 * لا تُشغِّل هذه الاختبارات `moh:sync` ولا تلمس قاعدة بيانات خارجية.
 */
class MohSyncSslTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['services.moh.ssl_verify' => null]);
        putenv('MOH_SSL_VERIFY');
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════
    // قرار `sslVerifyEnabled()` — العقد 1 و 2
    // ══════════════════════════════════════════════════════════════════

    /** مفقود ⇒ مُفعَّل (fail-secure). */
    public function test_ssl_verification_is_enabled_when_the_configuration_is_missing(): void
    {
        config(['services.moh.ssl_verify' => null]);

        $this->assertTrue(MohSync::sslVerifyEnabled());
    }

    /** غير صالح ⇒ مُفعَّل (لا تعطيل صامت). */
    public function test_ssl_verification_is_enabled_for_invalid_values(): void
    {
        foreach (['garbage', 'maybe', 123, [], ['true']] as $invalid) {
            config(['services.moh.ssl_verify' => $invalid]);

            $this->assertTrue(
                MohSync::sslVerifyEnabled(),
                'قيمة غير صالحة ('.json_encode($invalid).') يجب أن تفشل بأمان ⇒ مُفعَّل.'
            );
        }
    }

    /** التعطيل **صريح فقط**. */
    public function test_ssl_verification_is_disabled_only_for_explicit_opt_out_values(): void
    {
        foreach ([false, 'false', '0', 'off', 'no'] as $optOut) {
            config(['services.moh.ssl_verify' => $optOut]);

            $this->assertFalse(
                MohSync::sslVerifyEnabled(),
                'قيمة الإلغاء الصريحة ('.json_encode($optOut).') يجب أن تعطّل التحقق.'
            );
        }
    }

    public function test_ssl_verification_is_enabled_for_explicit_enabled_values(): void
    {
        foreach ([true, 'true', '1', 'on', 'yes'] as $enabled) {
            config(['services.moh.ssl_verify' => $enabled]);

            $this->assertTrue(MohSync::sslVerifyEnabled());
        }
    }

    /** إعادة تعيين بعد قراءة false (اختبار 5 يعيّنها) ⇒ تعود مُفعَّلة. */
    public function test_the_decision_can_be_re_evaluated_after_a_change(): void
    {
        config(['services.moh.ssl_verify' => false]);
        $this->assertFalse(MohSync::sslVerifyEnabled());

        config(['services.moh.ssl_verify' => true]);
        $this->assertTrue(MohSync::sslVerifyEnabled());
    }

    // ══════════════════════════════════════════════════════════════════
    // العقد 3 — الافتراضي في ملف الإعداد نفسه
    // ══════════════════════════════════════════════════════════════════

    /** `env('MOH_SSL_VERIFY', true)` ⇒ بغياب المتغير الافتراضي **مُفعَّل**. */
    public function test_the_services_config_defaults_to_enabled_when_the_env_var_is_absent(): void
    {
        putenv('MOH_SSL_VERIFY');
        unset($_ENV['MOH_SSL_VERIFY'], $_SERVER['MOH_SSL_VERIFY']);

        // إعادة تقييم تعبير ملف الإعداد بغياب المتغير
        config(['services.moh.ssl_verify' => env('MOH_SSL_VERIFY', true)]);

        $this->assertTrue(
            config('services.moh.ssl_verify'),
            'الافتراضي في config/services.php يجب أن يكون مُفعَّلًا.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // العقد 4 — عميل HTTP: `withoutVerifying()` يُنادى **فقط** عند التعطيل الصريح
    // ══════════════════════════════════════════════════════════════════

    public function test_the_http_client_does_not_disable_verification_when_enabled(): void
    {
        config(['services.moh.ssl_verify' => true]);

        Http::shouldReceive('timeout')->with(180)->andReturnSelf();
        Http::shouldReceive('withHeaders')->with(['Accept' => 'application/json'])->andReturnSelf();
        Http::shouldReceive('withoutVerifying')->never();

        MohSync::mohHttpClient(180);
    }

    public function test_the_http_client_keeps_the_timeout_and_headers_when_verification_is_enabled(): void
    {
        config(['services.moh.ssl_verify' => null]); // مفقود ⇒ مُفعَّل

        Http::shouldReceive('timeout')->with(180)->andReturnSelf();
        Http::shouldReceive('withHeaders')->with(['Accept' => 'application/json'])->andReturnSelf();
        Http::shouldReceive('withoutVerifying')->never();

        MohSync::mohHttpClient(180);
    }

    public function test_the_http_client_disables_verification_only_when_explicitly_opted_out(): void
    {
        config(['services.moh.ssl_verify' => false]);

        Http::shouldReceive('timeout')->with(180)->andReturnSelf();
        Http::shouldReceive('withHeaders')->andReturnSelf();
        Http::shouldReceive('withoutVerifying')->once()->andReturnSelf();

        MohSync::mohHttpClient(180);
    }
}
