<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingOverviewEquivalenceTest extends TestCase
{
    public function test_overview_financial_contract_and_query_measurement(): void
    {
        if (getenv('EXPECT_DB_DRIVER')) {
            $this->assertSame(getenv('EXPECT_DB_DRIVER'), DB::connection()->getDriverName());
        }
        Carbon::setTestNow(Carbon::parse('2026-11-11 14:30:00'));
        fake()->seed(112233);
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);
        $medicine = Medicine::factory()->create(['trade_name' => 'Test medicine']);
        $stock = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 40,
            'quantity' => 50,
            'is_available' => true,
        ]);
        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'Test customer',
            'phone' => '0599000111',
            'current_balance' => 0,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $item = ['pharmacy_medicine_id' => $stock->id, 'medicine_name' => 'Test medicine', 'unit_price' => 40, 'quantity' => 2];
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00'));
        $this->postJson('/api/pharmacy/accounting/sales', ['payment_method' => 'cash', 'items' => [$item]])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-11 06:00:00'));
        $this->postJson('/api/pharmacy/accounting/sales', ['customer_id' => $customer->id, 'payment_method' => 'credit', 'items' => [$item]])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-11 14:30:00'));
        $this->postJson('/api/pharmacy/accounting/sales', ['payment_method' => 'cash', 'items' => [$item]])->assertCreated();
        $this->postJson('/api/pharmacy/accounting/expenses', ['category_key' => 'transport', 'amount' => 15, 'payment_method' => 'cash', 'expense_date' => '2026-11-11'])->assertCreated();

        $supplier = Supplier::create(['pharmacy_id' => $pharmacy->id, 'name' => 'Test supplier', 'current_balance' => 0, 'is_active' => true]);
        $this->postJson('/api/pharmacy/accounting/purchases', [
            'supplier_id' => $supplier->id,
            'payment_method' => 'credit',
            'items' => [[
                'pharmacy_medicine_id' => $stock->id,
                'medicine_id' => $medicine->id,
                'medicine_name' => 'Test medicine',
                'unit_cost' => 12.5,
                'quantity' => 2,
                'line_discount' => 0,
            ]],
        ])->assertCreated();

        $snapshots = [];
        $counts = [];
        $timings = [];
        foreach (['today', '7d', '30d', 'month'] as $range) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson('/api/pharmacy/accounting/overview?range='.$range)->assertOk();
            $counts[$range] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $response->assertJsonPath('success', true)->assertJsonPath('data.range', $range)
                ->assertJsonStructure(['data' => ['kpis', 'series', 'expense_breakdown', 'recent_transactions', 'alerts', 'profit_indicator', 'comparison', 'receivables', 'barcode_coverage']]);
            $kpis = collect($response->json('data.kpis'))->keyBy('key');
            $this->assertSame(160.0, (float) $kpis['today_sales']['value']);
            $this->assertSame(25.0, (float) $kpis['today_purchases']['value']);
            $this->assertSame(25.0, (float) $response->json('data.receivables.supplier_balances'));
            // SQLite stores date casts as datetimes; preserve the baseline's date-bound behavior.
            $expectedExpense = DB::getDriverName() === 'sqlite' ? 0.0 : 15.0;
            $this->assertSame($expectedExpense, (float) $kpis['today_expenses']['value']);
            $this->assertSame(145.0, (float) $kpis['cash_balance']['value']);
            $this->assertSame(80.0, (float) $kpis['outstanding_debts']['value']);
            // 06:00 belongs to both inclusive buckets in the existing contract.
            $this->assertSame([80.0, 80.0, 80.0, 0.0], array_map('floatval', $response->json('data.series.today.data')));
            if ($range === 'today') {
                $this->assertSame(160.0, (float) $response->json('data.comparison.current'));
                $this->assertSame(80.0, (float) $response->json('data.comparison.previous'));
                $this->assertSame(135.0 - $expectedExpense, (float) $response->json('data.profit_indicator.net'));
            }
            $snapshots[$range] = $response->json();
            if (getenv('ACCOUNTING_BENCHMARK_PATH')) {
                $samples = [];
                for ($i = 0; $i < 5; $i++) {
                    $start = hrtime(true);
                    $this->getJson('/api/pharmacy/accounting/overview?range='.$range)->assertOk();
                    $samples[] = (hrtime(true) - $start) / 1_000_000;
                }
                sort($samples);
                $timings[$range] = round($samples[2], 2);
            }
        }

        if ($path = getenv('ACCOUNTING_BENCHMARK_PATH')) {
            file_put_contents($path, json_encode(['responses' => $snapshots, 'queries' => $counts, 'median_ms' => $timings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fwrite(STDERR, "\nACCOUNTING_QUERIES ".json_encode($counts)."\nACCOUNTING_MEDIAN_MS ".json_encode($timings)."\n");
        }
        Carbon::setTestNow();
    }

    public function test_overview_rejects_guest_and_patient(): void
    {
        $this->getJson('/api/pharmacy/accounting/overview')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'patient']));
        $this->getJson('/api/pharmacy/accounting/overview')->assertForbidden();
    }
}
