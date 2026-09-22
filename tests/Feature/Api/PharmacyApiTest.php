<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Models\PharmacyMedicine;
use App\Support\PharmacyAvailability;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PharmacyApiTest extends TestCase
{
    public function test_index_excludes_inactive_pharmacies(): void
    {
        Pharmacy::factory()->create(['pharmacy_name' => 'Active Pharmacy']);
        Pharmacy::factory()->inactive()->create(['pharmacy_name' => 'Inactive Pharmacy']);

        $response = $this->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_name', 'Active Pharmacy')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'pharmacy_name', 'address', 'latitude', 'longitude',
                    'phone_number', 'logo', 'avg_rating', 'ratings_count', 'ratings_avg',
                ]],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    public function test_index_filters_pharmacies_by_medicine_id(): void
    {
        $medicine = Medicine::factory()->create();

        $withStock = Pharmacy::factory()->create(['pharmacy_name' => 'Has Stock']);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $withStock->id,
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'is_available' => true,
        ]);

        $outOfStock = Pharmacy::factory()->create(['pharmacy_name' => 'Out Of Stock']);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $outOfStock->id,
            'medicine_id' => $medicine->id,
            'quantity' => 0,
            'is_available' => true,
        ]);

        Pharmacy::factory()->create(['pharmacy_name' => 'Unrelated']);

        $response = $this->getJson('/api/pharmacies?medicine_id='.$medicine->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_name', 'Has Stock');
    }

    public function test_show_returns_404_for_inactive_pharmacy(): void
    {
        // C3: الصيدليات غير النشطة لا تُكشف للموبايل.
        $pharmacy = Pharmacy::factory()->inactive()->create();

        $this->getJson('/api/pharmacies/'.$pharmacy->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_show_returns_pharmacy_for_active(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $this->getJson('/api/pharmacies/'.$pharmacy->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $pharmacy->id);
    }

    public function test_open_now_filter_returns_only_open_pharmacies(): void
    {
        $now = Carbon::parse('next sunday 13:00');
        Carbon::setTestNow($now);

        $openPharmacy = Pharmacy::factory()->create(['is_active' => true]);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $openPharmacy->id,
            'day_of_week' => 'Sunday',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);

        $closedPharmacy = Pharmacy::factory()->create(['is_active' => true]);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $closedPharmacy->id,
            'day_of_week' => 'Sunday',
            'open_time' => '08:00',
            'close_time' => '10:00',
            'is_closed' => false,
        ]);

        $response = $this->getJson('/api/pharmacies?open_now=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $openPharmacy->id)
            ->assertJsonPath('data.0.is_open_now', true);

        Carbon::setTestNow();
    }

    public function test_no_open_now_filter_preserves_existing_behavior(): void
    {
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        $response = $this->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $pharmacy->id);
    }

    public function test_open_now_filter_excludes_inactive_pharmacies(): void
    {
        $now = Carbon::parse('next sunday 13:00');
        Carbon::setTestNow($now);

        $activeOpen = Pharmacy::factory()->create(['is_active' => true]);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $activeOpen->id,
            'day_of_week' => 'Sunday',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);

        Pharmacy::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/pharmacies?open_now=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['data.0.is_active', false]);

        Carbon::setTestNow();
    }

    public function test_pharmacy_show_exposes_is_open_now(): void
    {
        $now = Carbon::parse('next sunday 13:00');
        Carbon::setTestNow($now);

        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => 'Sunday',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);

        $response = $this->getJson('/api/pharmacies/'.$pharmacy->id);

        $response->assertOk()
            ->assertJsonPath('data.is_open_now', true);

        Carbon::setTestNow();
    }
}
