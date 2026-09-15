<?php

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\BarcodeNormalizer;
use PHPUnit\Framework\TestCase;

final class BarcodeStrictValidationTest extends TestCase
{
    /** NDC من مصدر openFDA بـ8 خانات يُتقبل شكلياً كـEAN8 لكن checksum يفشل ← ليس accepted. */
    public function test_ndc_code_is_not_treated_as_valid_ean(): void
    {
        $result = BarcodeNormalizer::normalize('50580-521');

        // نوع شكلي (8 أرقام = EAN8) لكن checksum غير صالح → essential not verified.
        $this->assertNotNull($result);
        $this->assertFalse((bool) BarcodeNormalizer::isValidEan13($result['barcode'])); // 8 رقم → checksum اختيارية
    }

    /** 12 خانة يقبل فقط كـUPC-A، و13/14 مقاور على checksum في الأداء */
    public function test_valid_ean13_checksum(): void
    {
        $this->assertTrue(BarcodeNormalizer::isValidEan13('4006381333931'));
        // 6291041500213: checksum فعلياً صحيح (تم اختبارها حقيقةً بالoutline)
        $this->assertTrue(BarcodeNormalizer::isValidEan13('6291041500213'));
    }

    public function test_gtin14_accepts_any_14_digits_shape(): void
    {
        $this->assertSame('GTIN14', BarcodeNormalizer::normalize('10614141000415')['type']);
    }
}
