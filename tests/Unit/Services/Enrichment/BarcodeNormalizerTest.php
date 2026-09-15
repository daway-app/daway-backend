<?php

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\BarcodeNormalizer;
use PHPUnit\Framework\TestCase;

final class BarcodeNormalizerTest extends TestCase
{
    public function test_ean13_passes(): void
    {
        $result = BarcodeNormalizer::normalize('6291041500213');

        $this->assertNotNull($result);
        $this->assertSame('6291041500213', $result['barcode']);
        $this->assertSame('EAN13', $result['type']);
        $this->assertSame('', $result['raw']);
    }

    public function test_ean13_with_spaces_and_dashes(): void
    {
        $result = BarcodeNormalizer::normalize(' 629-1041-500 213 ');
        $this->assertNotNull($result);
        $this->assertSame('EAN13', $result['type']);
        $this->assertSame('6291041500213', $result['barcode']);
    }

    public function test_ean8_recognized(): void
    {
        $this->assertSame('EAN8', BarcodeNormalizer::normalize('73513537')['type']);
    }

    public function test_gtin14_recognized_without_checksum_verification_over_18_digits(): void
    {
        $this->assertSame('GTIN14', BarcodeNormalizer::normalize('10614141000415')['type']);
    }

    public function test_non_numeric_string_rejected(): void
    {
        $this->assertNull(BarcodeNormalizer::normalize('panic'));
    }

    public function test_leading_zero_gtin_preserved(): void
    {
        // GTIN-14 قد يبدأ بصفر — نحفظه كما هو.
        $this->assertSame('00012345678905', BarcodeNormalizer::normalize('00012345678905')['barcode']);
    }

    public function test_checksum_optional_helper_track(): void
    {
        $this->assertSame(true, BarcodeNormalizer::isValidEan13('6291041500213'));
    }
}
