<?php

namespace Tests\Unit\Services;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Services\Ai\MedicineResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineResolverTest extends TestCase
{
    use RefreshDatabase;

    private MedicineResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MedicineResolver;
    }

    public function test_pharmacies_sorted_by_distance_then_price(): void
    {
        $medicine = Medicine::create([
            'trade_name' => 'بانادول',
            'active_ingredient' => 'باراسيتامول',
            'is_available' => true,
        ]);

        // صيدليتان على مسافة متطابقة تماماً من المريض (تماثل حول خط العرض)
        // حتى يشتغل ترتيب السعر عند تعادل المسافة
        $patientLat = 31.5016;
        $near = Pharmacy::factory()->create(['latitude' => 31.5017, 'longitude' => 34.4668]);
        $nearCheap = Pharmacy::factory()->create(['latitude' => 31.5015, 'longitude' => 34.4668]);
        $far = Pharmacy::factory()->create(['latitude' => 32.5, 'longitude' => 35.5]);

        foreach ([
            [$far, 3.0],
            [$near, 20.0],
            [$nearCheap, 10.0],
        ] as [$pharmacy, $price]) {
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => $price,
                'quantity' => 5,
                'is_available' => true,
            ]);
        }

        $results = $this->resolver->pharmaciesFor(
            drugName: 'بانادول',
            latitude: $patientLat,
            longitude: 34.4668,
            radiusKm: 500,
        );

        $this->assertCount(3, $results);
        // نفس المسافة للأقربين → الأرخص أولاً، والبعيد أخيراً
        $this->assertEquals($nearCheap->id, $results[0]['pharmacy_id']);
        $this->assertEquals($near->id, $results[1]['pharmacy_id']);
        $this->assertEquals($far->id, $results[2]['pharmacy_id']);
    }

    public function test_radius_filters_out_far_pharmacies(): void
    {
        $medicine = Medicine::create([
            'trade_name' => 'أدول',
            'active_ingredient' => 'باراسيتامول',
        ]);

        $far = Pharmacy::factory()->create(['latitude' => 33.0, 'longitude' => 36.0]);
        PharmacyMedicine::create([
            'pharmacy_id' => $far->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 9,
            'is_available' => true,
        ]);

        $results = $this->resolver->pharmaciesFor(
            drugName: 'أدول',
            latitude: 31.5016,
            longitude: 34.4668,
            radiusKm: 15,
        );

        $this->assertEmpty($results);
    }

    public function test_unavailable_or_zero_quantity_excluded(): void
    {
        $medicine = Medicine::create([
            'trade_name' => 'بروفين',
            'active_ingredient' => 'ايبوبروفين',
        ]);

        // unique(pharmacy_id, medicine_id) → نستخدم صيدليتين مختلفتين
        $zeroQty = Pharmacy::factory()->create();
        $unavailable = Pharmacy::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $zeroQty->id,
            'medicine_id' => $medicine->id,
            'price' => 8,
            'quantity' => 0,
            'is_available' => true,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $unavailable->id,
            'medicine_id' => $medicine->id,
            'price' => 8,
            'quantity' => 5,
            'is_available' => false,
        ]);

        $this->assertEmpty($this->resolver->pharmaciesFor(drugName: 'بروفين'));
    }

    public function test_resolve_candidates_searches_both_catalogs(): void
    {
        Medicine::create([
            'trade_name' => 'بانادول سيام',
            'active_ingredient' => 'باراسيتامول',
        ]);

        \App\Models\MohMedicine::create([
            'trade_name' => 'بانادول اكسترا',
            'generic_name' => 'Paracetamol',
        ]);

        $candidates = $this->resolver->resolveCandidates('بانادول');

        $this->assertCount(1, $candidates['local']);
        $this->assertCount(1, $candidates['moh']);
    }

    public function test_alternatives_by_active_ingredient(): void
    {
        $base = Medicine::create([
            'trade_name' => 'بانادول',
            'active_ingredient' => 'باراسيتامول',
        ]);

        $alt = Medicine::create([
            'trade_name' => 'أدول',
            'active_ingredient' => 'باراسيتامول',
        ]);

        $alternatives = $this->resolver->alternatives($base->id);

        $this->assertCount(1, $alternatives);
        $this->assertEquals($alt->id, $alternatives[0]['id']);
    }

    public function test_arabic_query_resolves_moh_via_mapping_file(): void
    {
        // الكتالوج إنجليزي — LIKE '%بانادول%' ما بيلحقو
        \App\Models\MohMedicine::create([
            'trade_name' => 'PANADOL EXTRA TABLETS',
            'generic_name' => 'Paracetamol',
            'moh_product_id' => 555001,
        ]);

        $fixturePath = storage_path('app/private/resolver_mapping_fixture.json');
        @mkdir(dirname($fixturePath), 0775, true);
        file_put_contents($fixturePath, json_encode([
            [
                'id' => 1,
                'moh_product_id' => 555001,
                'moh_drug_id' => null,
                'product_class' => 'Human Drug',
                'name_en' => 'PANADOL EXTRA TABLETS',
                'name_ar' => 'بانادول اكسترا',
                'aliases' => ['panadol extra tablets', 'panadol extra', 'بانادول اكسترا'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $resolver = (new MedicineResolver)->setMappingPath($fixturePath);
            $candidates = $resolver->resolveCandidates('بانادول');

            $this->assertCount(1, $candidates['moh']);
            $this->assertEquals('PANADOL EXTRA TABLETS', $candidates['moh'][0]->trade_name);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function test_mapping_lookup_returns_hits_with_metadata(): void
    {
        $fixturePath = storage_path('app/private/resolver_mapping_fixture2.json');
        @mkdir(dirname($fixturePath), 0775, true);
        file_put_contents($fixturePath, "[\n".
            json_encode(['id' => 1, 'moh_product_id' => 1, 'moh_drug_id' => null, 'product_class' => 'Human Drug', 'name_en' => 'VOLTAREN GEL', 'name_ar' => 'فولتارين جل', 'aliases' => ['voltaren gel', 'فولتارين جل']], JSON_UNESCAPED_UNICODE).",\n".
            json_encode(['id' => 2, 'moh_product_id' => 2, 'moh_drug_id' => 77, 'product_class' => 'Human Drug', 'name_en' => 'VOLTAREN SR 75', 'name_ar' => 'فولتارين اس ار', 'aliases' => ['voltaren sr 75', 'فولتارين اس ار']], JSON_UNESCAPED_UNICODE)."\n]\n");

        try {
            $resolver = (new MedicineResolver)->setMappingPath($fixturePath);
            $hits = $resolver->lookupMapping('فولتارين');

            $this->assertCount(2, $hits);
            $this->assertSame('VOLTAREN GEL', $hits[0]['name_en']);
            $this->assertSame(77, $hits[1]['moh_drug_id']);
        } finally {
            @unlink($fixturePath);
        }
    }

    public function test_missing_mapping_file_is_graceful(): void
    {
        $resolver = (new MedicineResolver)->setMappingPath(storage_path('app/private/does_not_exist.json'));

        $this->assertSame([], $resolver->lookupMapping('بانادول'));

        $candidates = $resolver->resolveCandidates('بانادول');
        $this->assertIsArray($candidates);
        $this->assertInstanceOf(Collection::class, $candidates['moh']);
    }
}
