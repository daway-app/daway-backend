<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyMohBackfillDryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');
        $response->assertUnauthorized();
    }

    public function test_patient_user_gets_403(): void
    {
        $patient = User::create([
            'name' => 'Patient User',
            'email' => 'patient@test.com',
            'phone' => '05910000001',
            'password' => Hash::make('password'),
        ]);
        $patient->role = 'patient';
        $patient->is_active = true;
        $patient->email_verified_at = now();
        $patient->phone_verified_at = now();
        $patient->save();
        $patient->syncRoles(['patient']);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');
        $response->assertForbidden();
    }

    public function test_pharmacy_user_gets_403(): void
    {
        $pharmacyUser = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharmacy@test.com',
            'phone' => '05910000002',
            'password' => Hash::make('password'),
        ]);
        $pharmacyUser->role = 'pharmacy';
        $pharmacyUser->is_active = true;
        $pharmacyUser->email_verified_at = now();
        $pharmacyUser->phone_verified_at = now();
        $pharmacyUser->save();
        $pharmacyUser->syncRoles(['pharmacy']);

        Sanctum::actingAs($pharmacyUser);

        $response = $this->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');
        $response->assertForbidden();
    }

    public function test_admin_can_access_endpoint_and_gets_correct_json_shape(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'phone' => '05910000003',
            'password' => Hash::make('password'),
        ]);
        $admin->role = 'admin';
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->phone_verified_at = now();
        $admin->save();
        $admin->syncRoles(['admin']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total',
                    'linked',
                    'null',
                    'matched',
                    'ambiguous',
                    'unmatched',
                ],
            ]);

        $response->assertJson([
            'success' => true,
            'message' => 'MOH pharmacy backfill dry run completed successfully.',
        ]);
    }

    public function test_dry_run_is_read_only_and_does_not_modify_data(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin2@test.com',
            'phone' => '05910000004',
            'password' => Hash::make('password'),
        ]);
        $admin->role = 'admin';
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->phone_verified_at = now();
        $admin->save();
        $admin->syncRoles(['admin']);

        Sanctum::actingAs($admin);

        // Create medicine + MOH medicine
        $medicine = Medicine::create([
            'trade_name' => 'READ ONLY TEST MED',
            'active_ingredient' => 'Test Ingredient',
            'is_available' => true,
        ]);

        $mohMedicine = MohMedicine::create([
            'trade_name' => 'READ ONLY TEST MED',
            'moh_product_id' => 777777,
        ]);

        // Create pharmacy with PharmacyMedicine (NULL moh_medicine_id)
        $pharmacyUser = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharmacy2@test.com',
            'phone' => '05910000005',
            'password' => Hash::make('password'),
        ]);
        $pharmacyUser->role = 'pharmacy';
        $pharmacyUser->is_active = true;
        $pharmacyUser->email_verified_at = now();
        $pharmacyUser->phone_verified_at = now();
        $pharmacyUser->save();
        $pharmacyUser->syncRoles(['pharmacy']);

        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $pharmacyUser->id,
            'pharmacy_custom_id' => 'PT-ROTEST',
            'pharmacy_name' => 'Read Only Test Pharmacy',
            'address' => 'Test Address',
            'latitude' => 31.501600,
            'longitude' => 34.466800,
            'phone_number' => '05910000005',
            'region' => 'Test Region',
            'is_active' => true,
            'avg_rating' => 0,
            'profile_completed_at' => now(),
        ]));

        PharmacyMedicine::unguarded(fn () => PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => null,
            'price' => 15.00,
            'quantity' => 100,
            'is_available' => true,
            'min_stock' => 10,
        ]));

        // Snapshot BEFORE calling endpoint
        $pmCountBefore = PharmacyMedicine::count();
        $pmWithNullMohBefore = PharmacyMedicine::whereNull('moh_medicine_id')->count();
        $mohCountBefore = MohMedicine::count();
        $medCountBefore = Medicine::count();
        $pharmacyCountBefore = Pharmacy::count();

        // Call the dry-run endpoint
        $response = $this->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');
        $response->assertOk();

        // Verify NO data was changed
        $this->assertEquals($pmCountBefore, PharmacyMedicine::count(), 'pharmacy_medicines count must not change');
        $this->assertEquals($pmWithNullMohBefore, PharmacyMedicine::whereNull('moh_medicine_id')->count(), 'NULL moh_medicine_id count must not change');
        $this->assertEquals($mohCountBefore, MohMedicine::count(), 'moh_medicines count must not change');
        $this->assertEquals($medCountBefore, Medicine::count(), 'medicines count must not change');
        $this->assertEquals($pharmacyCountBefore, Pharmacy::count(), 'pharmacies count must not change');

        // Verify the specific PharmacyMedicine still has NULL moh_medicine_id
        $pharmacyMedicine = PharmacyMedicine::first();
        $this->assertNull($pharmacyMedicine->moh_medicine_id, 'moh_medicine_id must remain NULL (read-only)');
    }
}
