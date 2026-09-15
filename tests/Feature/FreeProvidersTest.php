<?php

namespace Tests\Feature;

use App\Models\MohMedicine;
use App\Services\Enrichment\Providers\DailyMedProvider;
use App\Services\Enrichment\Providers\MedicineQuery;
use App\Services\Enrichment\Providers\OpenFdaProvider;
use App\Services\Enrichment\Providers\RxNormProvider;
use App\Services\Enrichment\Providers\WikidataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مزوّدات مجانية — كل اختبار يستخدم HttpFake نهائيًا (لا استدعاء خارجي).
 * القاعدة الأساسية: لا النجاجة fake: النتيجة بدون بل/bar код — بلا لا لا.
 */
final class FreeProvidersTest extends TestCase
{
    private function moh(): MohMedicine
    {
        return MohMedicine::create([
            'trade_name' => 'PANADOL EXTRA 1',
            'generic_name' => null,
            'manufacturer' => 'GSK',
            'dosage_form' => 'Tablet',
            'moh_product_id' => 81001,
        ]);
    }

    private function medicineQuery(): MedicineQuery
    {
        return new MedicineQuery($this->moh(), 'PANADOL EXTRA 1', '', 'GSK', 'Tablet', '24 tablets');
    }

    // ========== RxNorm ==========
    public function test_rxnorm_success_returns_official_name_and_rxcui(): void
    {
        Http::fake([
            'https://rxnav.nlm.nih.gov/*' => Http::response([
                'drugGroup' => ['conceptGroup' => [[
                    'conceptProperties' => [[
                        'rxcui' => '202432',
                        'name' => 'Panadol Extra',
                        'strength' => '500/65mg',
                        'doseFormName' => 'Tablet',
                    ]],
                ]]],
            ], 200),
        ]);

        $result = (new RxNormProvider())->searchByMedicine($this->medicineQuery());

        $this->assertNotNull($result);
        $this->assertSame('rxnorm', $result->provider);
        $this->assertSame('Panadol Extra', $result->nameEn);
        $this->assertSame('RXCUI:202432', $result->sourceReference);
        // لا سلسلة الباركود — لا نعتبر الرمز المفقود الآن موجوداً
        $this->assertCount(0, $result->barcodes);
    }

    public function test_rxnorm_no_match_returns_null(): void
    {
        Http::fake(['https://rxnav.nlm.nih.gov/*' => Http::response(['drugGroup' => []], 200)]);

        $this->assertNull((new RxNormProvider())->searchByMedicine($this->medicineQuery()));
    }

    // ========== openFDA ==========
    public function test_openfda_success_returns_labeler_and_ingredient(): void
    {
        Http::fake([
            'https://api.fda.gov/*' => Http::response([
                'results' => [[
                    'active_ingredient' => ['PARACETAMOL'],
                    'dosage_form' => ['TABLET'],
                    'openfda' => [
                        'brand_name' => ['PANADOL EXTRA'],
                        'labeler_name' => ['GSK'],
                    ],
                    'id' => 'abc-123',
                ]],
            ], 200),
        ]);

        $result = (new OpenFdaProvider())->searchByMedicine($this->medicineQuery());

        $this->assertNotNull($result);
        $this->assertSame('openfda', $result->provider);
        $this->assertSame('PANADOL EXTRA', $result->nameEn);
        $this->assertSame('GSK', $result->manufacturer);
        $this->assertSame('openfda', $result->provider);
    }

    public function test_openfda_no_match_returns_null(): void
    {
        Http::fake(['https://api.fda.gov/*' => Http::response(['error' => ['code' => 'NOT_FOUND']], 404)]);

        $this->assertNull((new OpenFdaProvider())->searchByMedicine($this->medicineQuery()));
    }

    public function test_openfda_does_not_invent_barcode_from_ndc(): void
    {
        Http::fake([
            'https://api.fda.gov/*' => Http::response([
                'results' => [['openfda' => ['barcode' => ['0987654321'], 'brand_name' => ['X']]]],
            ], 200),
        ]);

        $result = (new OpenFdaProvider())->searchByMedicine($this->medicineQuery());

        // لا تجعْ fake barcode: حتى لو المصدر أعاد NDC غير صالح (9-digits)
        $this->assertCount(0, $result->barcodes);
    }

    // ========== DailyMed ==========
    public function test_dailymed_success_returns_setid_reference(): void
    {
        Http::fake([
            'https://dailymed.nlm.nih.gov/*' => Http::response([
                'data' => [['title' => 'PANADOL EXTRA 1 TABLET', 'setid' => 'ef3f42cb-53af-49b9-bf37-b2b4c14a029c'], []],
            ], 200),
        ]);

        $result = (new DailyMedProvider())->searchByMedicine($this->medicineQuery());

        $this->assertNotNull($result);
        $this->assertSame('PANADOL EXTRA 1 TABLET', $result->nameEn);
        $this->assertStringContainsString('SETID:', $result->sourceReference ?? '');
        $this->assertNull($result->imageUrl); // لا صور حتى yerنconfirmed قياساً
    }

    public function test_dailymed_no_match_returns_null(): void
    {
        Http::fake(['https://dailymed.nlm.nih.gov/*' => Http::response(['data' => []], 200)]);

        $this->assertNull((new DailyMedProvider())->searchByMedicine($this->medicineQuery()));
    }

    // ========== Wikidata ==========
    public function test_wikidata_success_returns_arabic_label_and_image(): void
    {
        // نستخدم closure — Http::fake patterns نصية قد تطابق query-string بشكل غير مرغوب
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, 'action=wbsearchentities')) {
                return Http::response([
                    'search' => [
                        ['id' => 'Q4120713', 'label' => 'Panadol', 'description' => 'trade name for paracetamol'],
                    ],
                ], 200);
            }
            if (str_contains($url, 'action=wbgetentities')) {
                return Http::response([
                    'entities' => [
                        'Q4120713' => [
                            'labels' => [
                                'ar' => ['value' => 'بانديل'],
                                'en' => ['value' => 'Panadol'],
                            ],
                            'claims' => [
                                'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Test image.png']]]],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $result = (new WikidataProvider())->searchByMedicine($this->medicineQuery());

        $this->assertNotNull($result);
        $this->assertSame('wikidata', $result->provider);
        $this->assertSame('بانديل', $result->nameAr);
        $this->assertSame('Panadol', $result->nameEn);
        // صورة من مصدر معروف: URL من Wikimedia Commons بالمسار الرسمي + is_verified=false
        $this->assertStringContainsString('upload.wikimedia.org', $result->imageUrl ?: '');
        $this->assertStringContainsString('Test_image.png', str_replace(' ', '_', $result->imageUrl ?: ''));
    }

    // ========== Silence ==========
    public function test_provider_timeout_does_not_crash(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $this->assertNull((new RxNormProvider())->searchByMedicine($this->medicineQuery()));
        $this->assertNull((new OpenFdaProvider())->searchByMedicine($this->medicineQuery()));
        $this->assertNull((new DailyMedProvider())->searchByMedicine($this->medicineQuery()));
        $this->assertNull((new WikidataProvider())->searchByMedicine($this->medicineQuery()));
    }

    public function test_http_429_is_handled_not_crash(): void
    {
        Http::fake(['*' => Http::response('{"error":"rate limited"}', 429)]);

        $this->assertNull((new RxNormProvider())->searchByMedicine($this->medicineQuery()));
        $this->assertNull((new OpenFdaProvider())->searchByMedicine($this->medicineQuery()));
    }

    public function test_malformed_json_is_graceful(): void
    {
        Http::fake(['*' => Http::response('<html>bad utf-8 json</html>', 500)]);

        $this->assertNull((new OpenFdaProvider())->searchByMedicine($this->medicineQuery()));
        $this->assertNull((new DailyMedProvider())->searchByMedicine($this->medicineQuery()));
    }

    // ========== Cost Guard (paid provider disabled) ==========
    public function test_paid_drugs_api_disabled_by_default(): void
    {
        config(['enrichment.paid_providers' => false, 'enrichment.drugs_api.enabled' => false]);

        $provider = new \App\Services\Enrichment\Providers\DrugsApiProvider();

        $this->assertFalse($provider->isEnabled());
        $this->assertFalse($provider->isFree());
    }
}
