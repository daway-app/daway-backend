<?php

namespace Tests\Unit\Services\Enrichment;

use App\Services\Enrichment\NameNormalizer;
use PHPUnit\Framework\TestCase;

final class NameNormalizerTest extends TestCase
{
    public function test_arabic_hamza_variants_unify(): void
    {
        $this->assertSame(
            NameNormalizer::arabic('أَحمد'),
            NameNormalizer::arabic('احمد')
        );
    }

    public function test_arabic_ta_marbuta_becomes_ha(): void
    {
        $this->assertSame('بانه', NameNormalizer::arabic('بانة'));
    }

    public function test_arabic_diacritics_removed(): void
    {
        $this->assertSame('بانادول', NameNormalizer::arabic('بَانَادُول'));
    }

    public function test_latin_lowercased_and_symbols_stripped(): void
    {
        // الحقول في الmodel هي trade_name وليس message. المعالجة: الرقام تُقطع نتيجة عدم وجود exception لالأرقام.
        $this->assertSame('panadol 500mg', NameNormalizer::latin('PANADOL 500mg'));
        $this->assertSame('panadol', NameNormalizer::latin('PANADOL (500mg)!!'));
    }

    public function test_similarity_identical_names_is_one(): void
    {
        $this->assertSame(1.0, NameNormalizer::similarity('Panadol Tablet', 'panadol tablet'));
    }

    public function test_similarity_zero_for_empty(): void
    {
        $this->assertSame(0.0, NameNormalizer::similarity('', 'panadol'));
        $this->assertSame(0.0, NameNormalizer::similarity('panadol', null));
    }

    public function test_similarity_comparable_values(): void
    {
        $this->assertGreaterThan(
            NameNormalizer::similarity('panadol', 'aspirin'),
            NameNormalizer::similarity('panadol', 'panadol extra')
        );
    }
}
