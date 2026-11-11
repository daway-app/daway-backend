<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingOverviewEquivalenceTest extends TestCase
{
    public function test_overview_financial_contract_and_query_measurement(): void
    {
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
        $this->postJson('/api/pharmacy/accounting/expenses', ['category_key' => 'transport', 'amount' => 15, 'payment_method' => 'cash'])->assertCreated();

        $snapshots = [];
        $counts = [];
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
            $this->assertSame(15.0, (float) $kpis['today_expenses']['value']);
            $this->assertSame(145.0, (float) $kpis['cash_balance']['value']);
            $this->assertSame(80.0, (float) $kpis['outstanding_debts']['value']);
            $snapshots[$range] = $response->json();
        }

        if ($path = getenv('ACCOUNTING_BENCHMARK_PATH')) {
            file_put_contents($path, json_encode(['responses' => $snapshots, 'queries' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fwrite(STDERR, "\nACCOUNTING_QUERIES ".json_encode($counts)."\n");
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
