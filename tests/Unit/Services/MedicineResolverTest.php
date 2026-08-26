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

        $near = Pharmacy::factory()->create(['latitude' => 31.5017, 'longitude' => 34.4668]);
        $nearCheap = Pharmacy::factory()->create(['latitude' => 31.5018, 'longitude' => 34.4669]);
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
            latitude: 31.5016,
            longitude: 34.4668,
            radiusKm: 500,
        );

        $this->assertCount(3, $results);
        // نفس المسافة تقريباً للأقربين → الأرخص أولاً
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

        $pharmacy = Pharmacy::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 8,
            'quantity' => 0,
            'is_available' => true,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
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
}
