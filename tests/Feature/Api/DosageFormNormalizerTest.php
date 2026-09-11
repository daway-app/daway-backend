<?php

namespace Tests\Feature\Api;

use App\Support\DosageFormNormalizer;
use Tests\TestCase;

class DosageFormNormalizerTest extends TestCase
{
    public function test_normalize_maps_common_catalog_values_to_canonical_arabic_names(): void
    {
        $this->assertSame('حبوب', DosageFormNormalizer::normalize('Film coated tablet'));
        $this->assertSame('بخاخ', DosageFormNormalizer::normalize('Nasal spray'));
        $this->assertSame('حقن', DosageFormNormalizer::normalize('Solution for injection (S.C)'));
        $this->assertSame('كبسولات', DosageFormNormalizer::normalize('Soft gelatin capsule'));
    }

    public function test_normalize_is_case_insensitive(): void
    {
        $this->assertSame('حبوب', DosageFormNormalizer::normalize('FILM COATED TABLET'));
        $this->assertSame('كريم', DosageFormNormalizer::normalize('Dermal Cream'));
    }

    public function test_normalize_returns_null_for_null_and_unknown_values(): void
    {
        $this->assertNull(DosageFormNormalizer::normalize(null));
        $this->assertNull(DosageFormNormalizer::normalize('Weird unknown'));
        $this->assertNull(DosageFormNormalizer::normalize(''));
    }

    public function test_gel_is_matched_but_gelatin_is_excluded_from_gel(): void
    {
        $this->assertSame('جل', DosageFormNormalizer::normalize('Gel'));

        $this->assertNull(DosageFormNormalizer::normalize('Gelatin'));
        $this->assertSame('كبسولات', DosageFormNormalizer::normalize('Soft gelatin capsule'));
    }

    public function test_for_input_accepts_canonical_arabic_names_verbatim(): void
    {
        $this->assertSame('حبوب', DosageFormNormalizer::forInput('حبوب'));
        $this->assertSame('بخاخ', DosageFormNormalizer::forInput('بخاخ'));
    }

    public function test_for_input_normalizes_free_english_input_to_canonical(): void
    {
        $this->assertSame('حبوب', DosageFormNormalizer::forInput('tablet'));
        $this->assertSame('حبوب', DosageFormNormalizer::forInput('Film coated tablet'));
        $this->assertSame('بخاخ', DosageFormNormalizer::forInput('spray'));
    }

    public function test_for_input_returns_null_for_unknown_or_blank_input(): void
    {
        $this->assertNull(DosageFormNormalizer::forInput('weird-unknown-form'));
        $this->assertNull(DosageFormNormalizer::forInput('   '));
        $this->assertNull(DosageFormNormalizer::forInput(null));
    }

    public function test_facets_returns_the_full_canonical_list(): void
    {
        $facets = DosageFormNormalizer::facets();

        $this->assertNotEmpty($facets);
        $this->assertContains('حبوب', $facets);
        $this->assertContains('كبسولات', $facets);
        $this->assertContains('حقن', $facets);
        $this->assertContains('محلول', $facets);
        $this->assertSame(array_values(array_unique($facets)), array_values($facets));
    }

    public function test_like_tokens_match_how_rows_are_stored(): void
    {
        $this->assertSame(['tab', 'caplet'], DosageFormNormalizer::likeTokens('حبوب'));
        $this->assertSame(['spray', 'spary', 'inhalation'], DosageFormNormalizer::likeTokens('بخاخ'));
        $this->assertSame([], DosageFormNormalizer::likeTokens('not-a-facet'));
    }

    public function test_exclude_tokens_prevent_substring_false_positives(): void
    {
        $this->assertSame(['gelatin', 'capsule'], DosageFormNormalizer::excludeTokens('جل'));
        $this->assertSame([], DosageFormNormalizer::excludeTokens('حبوب'));
    }

    public function test_every_facet_has_positive_tokens(): void
    {
        foreach (DosageFormNormalizer::facets() as $facet) {
            $this->assertNotEmpty(DosageFormNormalizer::likeTokens($facet));
        }
    }
}
