<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DebugChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Create category
        $category = Category::create([
            'name_ar' => 'Canonical Test Category',
            'name_en' => 'Canonical Test EN',
            'slug' => 'canonical-test',
            'is_active' => true,
            'sort_order' => 99,
        ]);

        // Create MohMedicine
        $mohMedicine = MohMedicine::create([
            'trade_name' => 'CANONICAL MOH MED',
            'moh_product_id' => 880001,
            'generic_name' => 'Test Generic',
        ]);

        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 880001,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $user = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharm1@daway.com',
            'phone' => '0591111111',
            'password' => Hash::make('password'),
        ]);
        $user->role = 'pharmacy';
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->phone_verified_at = now();
        $user->save();
        $user->syncRoles(['pharmacy']);

        $medicine = \App\Models\Medicine::create([
            'trade_name' => 'Local Test Med',
            'active_ingredient' => 'Test',
            'is_available' => true,
        ]);

        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-DBG',
            'pharmacy_name' => 'Test Pharmacy',
            'address' => 'Address',
            'latitude' => 31.501600,
            'longitude' => 34.466800,
            'phone_number' => '05911111111',
            'region' => 'Region',
            'is_active' => true,
            'avg_rating' => 0,
            'profile_completed_at' => now(),
        ]));

        PharmacyMedicine::unguarded(fn () => PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $mohMedicine->id,
            'price' => 15.00,
            'quantity' => 100,
            'is_available' => true,
            'min_stock' => 10,
        ]));

        echo "\n=== DEBUG INFO ===\n";
        echo "Category ID: " . $category->id . "\n";
        echo "MohMedicine ID: " . $mohMedicine->id . "\n";
        echo "MohMedicine moh_product_id: " . $mohMedicine->moh_product_id . "\n";
        echo "Pharmacy ID: " . $pharmacy->id . "\n";
        echo "CategoryMedicineLink: " . CategoryMedicineLink::where('category_id', $category->id)->count() . "\n";
        echo "PharmacyMedicine with moh_medicine_id: " . PharmacyMedicine::where('moh_medicine_id', $mohMedicine->id)->count() . "\n";

        // Test the join query
        $result = DB::table('categories as c')
            ->where('c.id', $category->id)
            ->where('c.is_active', true)
            ->whereExists(function ($q) use ($category, $mohMedicine) {
                $q->selectRaw(1)
                    ->from('category_medicine_links as l')
                    ->join('moh_medicines as m', 'l.moh_product_id', '=', 'm.moh_product_id')
                    ->join('pharmacy_medicines as pm', 'm.id', '=', 'pm.moh_medicine_id')
                    ->join('pharmacies as p', 'pm.pharmacy_id', '=', 'p.id')
                    ->where('l.category_id', $category->id)
                    ->where('pm.moh_medicine_id', $mohMedicine->id)
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->exists();

        echo "\nJoin query result: " . ($result ? 'YES' : 'NO') . "\n";

        // Debug: check each step
        $join1 = DB::table('category_medicine_links as l')
            ->join('moh_medicines as m', 'l.moh_product_id', '=', 'm.moh_product_id')
            ->where('l.category_id', $category->id)
            ->count();
        echo "Step 1 (category → moh): " . $join1 . "\n";

        $join2 = DB::table('category_medicine_links as l')
            ->join('moh_medicines as m', 'l.moh_product_id', '=', 'm.moh_product_id')
            ->join('pharmacy_medicines as pm', 'm.id', '=', 'pm.moh_medicine_id')
            ->where('l.category_id', $category->id)
            ->count();
        echo "Step 2 (moh → pharmacy_medicines): " . $join2 . "\n";

        $join3 = DB::table('category_medicine_links as l')
            ->join('moh_medicines as m', 'l.moh_product_id', '=', 'm.moh_product_id')
            ->join('pharmacy_medicines as pm', 'm.id', '=', 'pm.moh_medicine_id')
            ->join('pharmacies as p', 'pm.pharmacy_id', '=', 'p.id')
            ->where('l.category_id', $category->id)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true)
            ->count();
        echo "Step 3 (with availability): " . $join3 . "\n";
    }

    public function test_debug_chain(): void
    {
        $this->assertTrue(true); // The debug info is printed in setUp
    }
}
