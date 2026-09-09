<?php

namespace Tests\Feature\Security;

use App\Rules\SecureImageUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * C4: عقد قاعدة روابط الصور الآمنة.
 * القائمة البيضاء: https فقط + نطاقات Cloudinary (بدون bare apex).
 */
class SecureImageUrlRuleTest extends TestCase
{
    /**
     * Helper: تشغيل القاعدة عبر Validator بسلوك واقعي (كما في الـ requests).
     */
    private function validateUrl(mixed $value): bool
    {
        return Validator::make(
            ['image_url' => $value],
            ['image_url' => ['required', new SecureImageUrl]]
        )->passes();
    }

    /**
     * Helper: استدعاء القاعدة مباشرة مع Closure يسجل الإخفاقات.
     *
     * @return array<int, string>
     */
    private function ruleFailures(mixed $value): array
    {
        $failures = [];

        (new SecureImageUrl)->validate('image_url', $value, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        return $failures;
    }

    public function test_canonical_cloudinary_url_passes(): void
    {
        $this->assertTrue($this->validateUrl('https://res.cloudinary.com/demo/image/upload/sample.jpg'));
    }

    public function test_subdomain_of_res_cloudinary_passes(): void
    {
        $this->assertTrue($this->validateUrl('https://mycloud.res.cloudinary.com/x.jpg'));
    }

    public function test_res_cloudinary_domain_with_evil_suffix_fails(): void
    {
        // res.cloudinary.com.evil.com — النطاق الفعلي evil.com وليس cloudinary
        $this->assertFalse($this->validateUrl('https://res.cloudinary.com.evil.com/x.jpg'));
    }

    public function test_lookalike_prefix_domain_fails(): void
    {
        // evilcloudinary.com ينتهي بـ "cloudinary.com" كسلسلة لكنه نطاق ملكية أخرى
        $this->assertFalse($this->validateUrl('https://evilcloudinary.com/x.jpg'));
    }

    public function test_unrelated_domain_fails(): void
    {
        $this->assertFalse($this->validateUrl('https://notcloudinary.com/x.jpg'));
    }

    public function test_bare_apex_cloudinary_fails(): void
    {
        // العقد الجديد: bare apex cloudinary.com غير مسموح — res.cloudinary.com فقط
        $this->assertFalse($this->validateUrl('https://cloudinary.com/x.jpg'));
    }

    public function test_non_https_url_fails(): void
    {
        $this->assertFalse($this->validateUrl('http://res.cloudinary.com/x.jpg'));
    }

    public function test_javascript_scheme_fails(): void
    {
        $this->assertFalse($this->validateUrl('javascript:alert(1)'));
    }

    public function test_empty_string_passes_nullable_semantics(): void
    {
        // القاعدة نفسها تتجاهل القيم الفارغة (دلالة nullable) —
        // لذلك نفحصها مباشرة بالـ Closure: لا إخفاقات مسجلة.
        $this->assertSame([], $this->ruleFailures(''));
        $this->assertSame([], $this->ruleFailures(null));
    }

    public function test_rule_records_failure_message_with_attribute_name(): void
    {
        $failures = $this->ruleFailures('https://evil.com/x.jpg');

        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('image_url', $failures[0]);
    }
}
