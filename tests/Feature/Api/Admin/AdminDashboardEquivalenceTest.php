<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Medicine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardEquivalenceTest extends TestCase
{
    private function forgetDashboard(): void
    {
        Cache::forget('admin_api_dashboard_stats');
        Cache::forget('admin_api_recent_activities');
    }

    public function test_dashboard_preserves_periods_contract_and_cold_query_count(): void
    {
        if (getenv('EXPECT_DB_DRIVER')) {
            $this->assertSame(getenv('EXPECT_DB_DRIVER'), DB::connection()->getDriverName());
        }
        Carbon::setTestNow(Carbon::parse('2026-11-11 14:30:00'));
        fake()->seed(445566);
        $admin = User::factory()->admin()->create(['name' => 'Admin Test', 'must_change_password' => false]);
        $admin->syncRoles(['admin']);
        foreach (['2026-10-01 00:00:00', '2026-11-01 00:00:00', '2026-11-10 12:00:00'] as $index => $createdAt) {
            $patient = User::factory()->patient()->create(['name' => 'Patient '.$index]);
            $patient->forceFill(['created_at' => $createdAt])->save();
        }
        $pharmacist = User::factory()->pharmacy()->create(['name' => 'Pharmacist']);
        $pharmacist->forceFill(['created_at' => '2026-11-10 10:00:00'])->save();
        $oldPharmacist = User::factory()->pharmacy()->inactive()->create(['name' => 'Old pharmacist']);
        $oldPharmacist->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();
        $medicine = Medicine::factory()->create(['trade_name' => 'Recent']);
        $medicine->forceFill(['created_at' => '2026-11-10 09:00:00'])->save();
        $oldMedicine = Medicine::factory()->create(['trade_name' => 'Old']);
        $oldMedicine->forceFill(['created_at' => '2026-08-01 09:00:00'])->save();
        Sanctum::actingAs($admin);
        $this->forgetDashboard();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/admin/dashboard')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.totalPatients', 3)
            ->assertJsonPath('data.stats.activePharmacies', 1)
            ->assertJsonPath('data.stats.totalMedicines', 2)
            ->assertJsonPath('data.stats.newPharmaciesThisWeek', 1)
            ->assertJsonPath('data.stats.medicinesAddedRecently', 1)
            ->assertJsonPath('data.stats.trendPatients', 0)
            ->assertJsonStructure(['data' => ['stats' => ['chartData', 'stockStatus', 'userRoles'], 'recent_activities']]);
        if ($path = getenv('DASHBOARD_BENCHMARK_PATH')) {
            $samples = [];
            for ($i = 0; $i < 5; $i++) {
                $this->forgetDashboard();
                $start = hrtime(true);
                $this->getJson('/api/admin/dashboard')->assertOk();
                $samples[] = (hrtime(true) - $start) / 1_000_000;
            }
            sort($samples);
            $result = ['response' => $response->json(), 'queries' => $count, 'median_ms' => round($samples[2], 2)];
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fwrite(STDERR, "\nADMIN_DASHBOARD ".json_encode(['queries' => $count, 'median_ms' => $result['median_ms']])."\n");
        }
        Carbon::setTestNow();
    }

    public function test_non_admin_cannot_access_dashboard(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->patient()->create());
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
