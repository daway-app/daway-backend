<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CanonicalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function createCategoryAndMohMedicine(string $suffix = ''): array
    {
        $category = Category::create([
            'name_ar' => 'Canonical Test Category ' . $suffix,
            'name_en' => 'Canonical Test EN ' . $suffix,
            'slug' => 'canonical-test-' . uniqid(),
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $mohMedicine = MohMedicine::create([
            'trade_name' => 'CANONICAL MOH MED ' . $suffix . uniqid(),
            'moh_product_id' => 880000 + rand(1, 99999),
            'generic_name' => 'Test Generic' . $suffix,
        ]);

        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => $mohMedicine->moh_product_id,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        return [$category, $mohMedicine];
    }

    private function createPharmacyWithInventory(MohMedicine $mohMedicine, bool $available = true, int $quantity = 100, bool $active = true): Pharmacy
    {
        $user = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharm_' . uniqid() . '@daway.com',
            'phone' => '059' . uniqid(),
            'password' => Hash::make('password'),
        ]);
        $user->role = 'pharmacy';
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->phone_verified_at = now();
        $user->save();
        $user->syncRoles(['pharmacy']);

        $medicine = Medicine::create([
            'trade_name' => 'Local ' . uniqid(),
            'active_ingredient' => 'Test Ingredient',
            'is_available' => true,
        ]);

        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PT-' . uniqid(),
            'pharmacy_name' => 'Test Pharmacy ' . uniqid(),
            'address' => 'Test Address',
            'latitude' => 31.501600,
            'longitude' => 34.466800,
            'phone_number' => '059' . uniqid(),
            'region' => 'Test Region',
            'is_active' => $active,
            'avg_rating' => 0,
            'profile_completed_at' => now(),
        ]));

        PharmacyMedicine::unguarded(fn () => PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $mohMedicine->id,
            'price' => 15.00,
            'quantity' => $quantity,
            'is_available' => $available,
            'min_stock' => 10,
        ]));

        return $pharmacy;
    }

    private function categoryHasAvailableChain(int $categoryId, int $mohMedicineId): bool
    {
        return (bool) DB::table('categories as c')
            ->where('c.id', $categoryId)
            ->where('c.is_active', true)
            ->whereExists(function ($q) use ($categoryId, $mohMedicineId) {
                $q->selectRaw(1)
                    ->from('category_medicine_links as l')
                    ->join('moh_medicines as m', 'l.moh_product_id', '=', 'm.moh_product_id')
                    ->join('pharmacy_medicines as pm', 'm.id', '=', 'pm.moh_medicine_id')
                    ->join('pharmacies as p', 'pm.pharmacy_id', '=', 'p.id')
                    ->where('l.category_id', $categoryId)
                    ->where('pm.moh_medicine_id', $mohMedicineId)
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->exists();
    }

    public function test_available_canonical_chain(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test1');
        $this->createPharmacyWithInventory($mohMedicine, true, 100, true);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertTrue($exists, 'Category should appear with full available canonical chain');
    }

    public function test_out_of_stock_excluded(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test2');
        $this->createPharmacyWithInventory($mohMedicine, true, 0, true);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertFalse($exists, 'Category should NOT appear when quantity = 0');
    }

    public function test_unavailable_excluded(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test3');
        $this->createPharmacyWithInventory($mohMedicine, false, 100, true);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertFalse($exists, 'Category should NOT appear when is_available = false');
    }

    public function test_inactive_pharmacy_excluded(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test4');
        $this->createPharmacyWithInventory($mohMedicine, true, 100, false);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertFalse($exists, 'Category should NOT appear when pharmacy is inactive');
    }

    public function test_wrong_moh_link_excluded(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test5');

        $wrongMohMedicine = MohMedicine::create([
            'trade_name' => 'WRONG MOH ' . uniqid(),
            'moh_product_id' => 770000 + rand(1, 99999),
        ]);

        $this->createPharmacyWithInventory($wrongMohMedicine, true, 100, true);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertFalse($exists, 'Category should NOT appear when PharmacyMedicine links to wrong MOH');
    }

    public function test_any_pharmacy_has_available_inventory(): void
    {
        [$category, $mohMedicine] = $this->createCategoryAndMohMedicine('_test6');

        $this->createPharmacyWithInventory($mohMedicine, true, 0, true);
        $this->createPharmacyWithInventory($mohMedicine, true, 50, true);

        $exists = $this->categoryHasAvailableChain($category->id, $mohMedicine->id);

        $this->assertTrue($exists, 'Category should appear when at least one pharmacy has available inventory');
    }
}
