<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Models\User;
use App\Support\Haversine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmacySearchRadiusTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        return User::factory()->patient()->create();
    }

    private function pharmacyAt(string $name, float $lat, float $lng, array $extra = []): Pharmacy
    {
        return Pharmacy::factory()->create(array_merge([
            'pharmacy_name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
        ], $extra));
    }

    public function test_pharmacy_search_is_publicly_accessible(): void
    {
        // /api/pharmacies يبقى عاماً (يدعم Mobile بدون تسجيل + لا يكسر PharmacyApiTest).
        $this->pharmacyAt('A', 31.5016, 34.4668);

        $this->getJson('/api/pharmacies')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_pharmacy_search_orders_by_distance_when_geo_provided(): void
    {
        $patient = $this->patient();

        $near = $this->pharmacyAt('Near Pharmacy', 31.5016, 34.4668);
        $medium = $this->pharmacyAt('Medium Pharmacy', 31.55, 34.48);
        $far = $this->pharmacyAt('Far Pharmacy', 31.6, 34.55);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?latitude=31.5&longitude=34.47');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        $data = $response->json('data');
        $this->assertSame('Near Pharmacy', $data[0]['pharmacy_name']);
        $this->assertSame('Medium Pharmacy', $data[1]['pharmacy_name']);
        $this->assertSame('Far Pharmacy', $data[2]['pharmacy_name']);

        $this->assertLessThanOrEqual($data[1]['distance_km'], $data[0]['distance_km']);
        $this->assertLessThanOrEqual($data[2]['distance_km'], $data[1]['distance_km']);
    }

    public function test_pharmacy_search_filters_by_radius_km(): void
    {
        $patient = $this->patient();

        $near = $this->pharmacyAt('Near Pharmacy', 31.5016, 34.4668);
        $medium = $this->pharmacyAt('Medium Pharmacy', 31.55, 34.48);
        $far = $this->pharmacyAt('Far Pharmacy', 31.9, 34.9);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?latitude=31.5&longitude=34.47&radius_km=5');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('pharmacy_name')->all();

        $expectedNearDist = Haversine::kmBetween(31.5, 34.47, 31.5016, 34.4668);
        $expectedMediumDist = Haversine::kmBetween(31.5, 34.47, 31.55, 34.48);
        $expectedFarDist = Haversine::kmBetween(31.5, 34.47, 31.9, 34.9);

        $this->assertLessThanOrEqual(5, $expectedNearDist, 'near should be <= 5km from origin');
        $this->assertGreaterThan(5, $expectedMediumDist, 'medium should be > 5km from origin');
        $this->assertGreaterThan(5, $expectedFarDist, 'far should be > 5km from origin');
        $this->assertContains('Near Pharmacy', $names);
        $this->assertNotContains('Medium Pharmacy', $names);
        $this->assertNotContains('Far Pharmacy', $names);
    }

    public function test_pharmacy_search_returns_distance_km_and_is_open_now_when_geo_provided(): void
    {
        $patient = $this->patient();
        $today = Carbon::now()->format('l');

        $pharmacy = $this->pharmacyAt('Open Pharmacy', 31.5016, 34.4668);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => $today,
            'open_time' => '00:00',
            'close_time' => '23:59',
            'is_closed' => false,
        ]);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?latitude=31.5&longitude=34.47');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.pharmacy_name', 'Open Pharmacy');

        $row = $response->json('data.0');
        $this->assertIsFloat($row['distance_km']);
        $this->assertIsBool($row['is_open_now']);
        $this->assertTrue($row['is_open_now']);
    }

    public function test_pharmacy_search_without_geo_returns_distance_km_null(): void
    {
        $patient = $this->patient();

        $this->pharmacyAt('A', 31.5016, 34.4668);
        $this->pharmacyAt('B', 31.55, 34.48);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertNull($row['distance_km']);
            $this->assertArrayHasKey('is_open_now', $row);
        }
    }

    public function test_pharmacy_search_includes_is_open_now_false_for_closed_hours(): void
    {
        $patient = $this->patient();
        $today = Carbon::now()->format('l');

        $pharmacy = $this->pharmacyAt('Closed Today Pharmacy', 31.5016, 34.4668);
        PharmacyHour::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => $today,
            'open_time' => '00:00',
            'close_time' => '23:59',
            'is_closed' => true,
        ]);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?latitude=31.5&longitude=34.47');

        $response->assertOk()
            ->assertJsonPath('data.0.pharmacy_name', 'Closed Today Pharmacy')
            ->assertJsonPath('data.0.is_open_now', false);
    }

    public function test_pharmacy_search_paginates(): void
    {
        $patient = $this->patient();

        for ($i = 1; $i <= 5; $i++) {
            $this->pharmacyAt("Pharmacy {$i}", 31.5 + ($i * 0.01), 34.47 + ($i * 0.01));
        }

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?page=1&per_page=2');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_pharmacy_search_includes_rating_field(): void
    {
        $patient = $this->patient();

        $pharmacy = $this->pharmacyAt('Rated Pharmacy', 31.5016, 34.4668, [
            'avg_rating' => 4.7,
        ]);

        $response = $this->actingAs($patient, 'sanctum')
            ->getJson('/api/pharmacies?latitude=31.5&longitude=34.47');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.pharmacy_name', 'Rated Pharmacy')
            ->assertJsonPath('data.0.avg_rating', 4.7);

        $row = $response->json('data.0');
        $this->assertEqualsWithDelta(4.7, $row['avg_rating'], 0.01);
    }
}