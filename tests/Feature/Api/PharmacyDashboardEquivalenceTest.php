<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyDashboardEquivalenceTest extends TestCase
{
    public function test_dashboard_counts_contract_and_isolation(): void
    {
        if (getenv('EXPECT_DB_DRIVER')) {
            $this->assertSame(getenv('EXPECT_DB_DRIVER'), DB::connection()->getDriverName());
        }
        Carbon::setTestNow(Carbon::parse('2026-11-11 14:30:00'));
        fake()->seed(998877);
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);
        $patient = User::factory()->patient()->create(['name' => 'Test Patient']);
        $other = Pharmacy::factory()->create();
        foreach ([[0, true], [1, true], [10, false], [11, true]] as $index => [$qty, $available]) {
            $medicine = Medicine::factory()->create(['trade_name' => 'Medicine '.$index]);
            PharmacyMedicine::create(['pharmacy_id' => $pharmacy->id, 'medicine_id' => $medicine->id, 'price' => 10, 'quantity' => $qty, 'is_available' => $available]);
        }
        PharmacyMedicine::create(['pharmacy_id' => $other->id, 'medicine_id' => $medicine->id, 'price' => 10, 'quantity' => 1, 'is_available' => true]);
        PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'user_id' => $patient->id, 'medicine_id' => $medicine->id, 'message' => 'First', 'status' => 'new']);
        PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'user_id' => $patient->id, 'medicine_id' => $medicine->id, 'message' => 'Second', 'status' => 'answered']);
        PatientInquiry::factory()->create(['pharmacy_id' => $other->id, 'user_id' => $patient->id, 'medicine_id' => $medicine->id, 'status' => 'new']);
        Rating::factory()->create(['pharmacy_id' => $pharmacy->id, 'user_id' => $patient->id, 'stars_rating' => 5, 'comment' => 'Good', 'created_at' => '2026-11-11 14:30:00']);
        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/pharmacy/dashboard/stats')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertJsonPath('data.total_medicines', 4)
            ->assertJsonPath('data.available_count', 2)
            ->assertJsonPath('data.low_count', 2)
            ->assertJsonPath('data.out_count', 1)
            ->assertJsonPath('data.pending_inquiries', 1)
            ->assertJsonPath('data.total_inquiries', 2)
            ->assertJsonPath('data.new_ratings_this_week', 1)
            ->assertJsonStructure(['success', 'message', 'data' => ['latest_ratings', 'latest_inquiries', 'low_stock_items']]);
        if ($path = getenv('DASHBOARD_BENCHMARK_PATH')) {
            $samples = [];
            for ($i = 0; $i < 5; $i++) {
                $start = hrtime(true);
                $this->getJson('/api/pharmacy/dashboard/stats')->assertOk();
                $samples[] = (hrtime(true) - $start) / 1_000_000;
            }
            sort($samples);
            $result = ['response' => $response->json(), 'queries' => $count, 'median_ms' => round($samples[2], 2)];
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fwrite(STDERR, "\nPHARMACY_DASHBOARD ".json_encode(['queries' => $count, 'median_ms' => $result['median_ms']])."\n");
        }
        Carbon::setTestNow();
    }

    public function test_dashboard_rejects_guest_and_patient(): void
    {
        $this->getJson('/api/pharmacy/dashboard/stats')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->patient()->create());
        $this->getJson('/api/pharmacy/dashboard/stats')->assertForbidden();
    }
}
