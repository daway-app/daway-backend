<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Database\Seeders\MedicineSeeder;
use Database\Seeders\PharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PharmacySeederProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_medicine_seeder_creates_required_medicines(): void
    {
        Artisan::call('db:seed', ['--class' => MedicineSeeder::class]);

        $this->assertDatabaseHas('medicines', ['trade_name' => 'Panadol']);
        $this->assertDatabaseHas('medicines', ['trade_name' => 'Amoxil']);
        $this->assertDatabaseHas('medicines', ['trade_name' => 'Glucophage']);
        $this->assertDatabaseHas('medicines', ['trade_name' => 'Ventolin']);
        $this->assertDatabaseHas('medicines', ['trade_name' => 'Augmentin']);
    }

    public function test_local_environment_creates_demo_pharmacies_with_inventory(): void
    {
        // The app() environment is 'testing' which matches 'local', 'testing'
        $this->app['env'] = 'testing';
        
        Artisan::call('db:seed', ['--class' => MedicineSeeder::class]);
        Artisan::call('db:seed', ['--class' => PharmacySeeder::class]);

        // Demo pharmacies should exist
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-2001']);
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-2010']);

        // Demo inventory should exist (41 pharmacy_medicines)
        $pmCount = PharmacyMedicine::count();
        $this->assertGreaterThan(0, $pmCount, 'Demo pharmacy_medicines should be created in testing env');

        // Verify the specific medicines from PH-2001
        $panadol = Medicine::where('trade_name', 'Panadol')->first();
        $this->assertNotNull($panadol, 'Panadol medicine should exist from MedicineSeeder');

        // PH-1234 (non-demo) should also exist
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-1234']);
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-5678']);
    }

    public function test_production_environment_skips_demo_inventory(): void
    {
        // Seed RolePermissionSeeder first (needed by PharmacySeeder to work)
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // Simulate production environment by mocking app()->environment()
        $this->app['env'] = 'production';

        // Use a custom seeder that simulates production behavior by
        // temporarily faking the environment check
        $seeder = new PharmacySeeder($this->app);

        // Mock the environment check by using Reflection to verify
        // the logic: app()->environment('local', 'testing') returns false in production
        $environment = $this->app->environment();
        $this->assertEquals('production', $environment, 'Environment should be set to production');

        // Run the seeder - it should skip demo pharmacies in production
        // But it will try to create the users/pharmacies first (PH-1234, PH-5678)
        // which requires the RolePermissionSeeder to have run
        Artisan::call('db:seed', ['--class' => MedicineSeeder::class]);

        // Manually call the seeder with production environment
        // The seeder checks app()->environment('local', 'testing')
        // In production this should be false
        $seeder->run();

        // Demo pharmacies (PH-2001–PH-2010) should NOT exist in production
        $demoPharmacies = Pharmacy::whereIn('pharmacy_custom_id', [
            'PH-2001', 'PH-2002', 'PH-2003', 'PH-2004', 'PH-2005',
            'PH-2006', 'PH-2007', 'PH-2008', 'PH-2009', 'PH-2010',
        ])->count();

        $this->assertEquals(0, $demoPharmacies, 'Demo pharmacies should NOT be created in production environment');

        // Demo inventory should NOT exist
        $demoInventory = PharmacyMedicine::count();
        $this->assertEquals(0, $demoInventory, 'Demo pharmacy_medicines should NOT be created in production environment');

        // Base pharmacies (PH-1234, PH-5678) should exist
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-1234']);
        $this->assertDatabaseHas('pharmacies', ['pharmacy_custom_id' => 'PH-5678']);
    }
}
