<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 حارس انحدار: شروط الـ OR في بحث الأدوية/المخزون يجب أن تبقى مجمّعة داخل
 * نطاق العلاقة `whereHas('medicine')`. بدون التجميع يصبح المنطق
 * `trade_name LIKE ? OR active_ingredient LIKE ? OR trade_name_ar LIKE ?`
 * مباشرةً في EXISTS، فيمكن أن تُرجع الصفحة صفوفاً لا تنتمي لهذه الصيدلية.
 *
 * إضافةً: تأكيد عدم وجود استعلام صيدلية مكرر (العلاقة محمّلة من الوسيط).
 */
class PharmacyMedicineSearchScopeTest extends TestCase
{
    /**
     * @return array{0: User, 1: Pharmacy}
     */
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function attach(Pharmacy $pharmacy, Medicine $medicine, int $quantity = 20, float $price = 5): void
    {
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'quantity' => $quantity,
        ]);
    }

    public function test_medicines_search_does_not_leak_other_pharmacies_rows(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [, $pharmacyB] = $this->pharmacyUserWithPharmacy();

        // دواء تملكه A فقط، واسمه لا يطابق نصّ البحث إطلاقاً.
        $ownedByA = Medicine::factory()->create([
            'trade_name' => 'OwnedByA',
            'active_ingredient' => 'ingredient-a',
            'trade_name_ar' => '',
        ]);
        $this->attach($pharmacyA, $ownedByA);

        // دواء تملكه B فقط، ونصّ البحث يطابق اسمه.
        $ownedByB = Medicine::factory()->create([
            'trade_name' => 'OnlyB',
            'active_ingredient' => 'ingredient-b',
            'trade_name_ar' => '',
        ]);
        $this->attach($pharmacyB, $ownedByB);

        // مشكلة الـOR كانت تُرجع OwnedByA رغم أن نصّ البحث لا يطابق أياً من حقوله
        // (لأن الـOR غير المجمّع يفلت من نطاق اسم الدواء إلى صفوف أخرى).
        $this->actingAs($userA)->get('/pharmacy/medicines?q=OnlyB')
            ->assertOk()
            ->assertDontSee('OwnedByA');
    }

    public function test_medicines_search_still_matches_own_rows(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $hit = Medicine::factory()->create(['trade_name' => 'Panadol Extra', 'active_ingredient' => 'Paracetamol']);
        $miss = Medicine::factory()->create(['trade_name' => 'Brufen 400', 'active_ingredient' => 'Ibuprofen']);

        $this->attach($pharmacy, $hit);
        $this->attach($pharmacy, $miss);

        $this->actingAs($user)->get('/pharmacy/medicines?q=Para')
            ->assertOk()
            ->assertSee('Panadol Extra')
            ->assertDontSee('Brufen 400');
    }

    public function test_inventory_search_does_not_leak_other_pharmacies_rows(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [, $pharmacyB] = $this->pharmacyUserWithPharmacy();

        $ownedByA = Medicine::factory()->create(['trade_name' => 'OwnedByA', 'active_ingredient' => 'x']);
        $this->attach($pharmacyA, $ownedByA);

        $ownedByB = Medicine::factory()->create(['trade_name' => 'OnlyB', 'active_ingredient' => 'y']);
        $this->attach($pharmacyB, $ownedByB);

        $this->actingAs($userA)->get('/pharmacy/inventory?q=OnlyB')
            ->assertOk()
            ->assertDontSee('OwnedByA');
    }

    public function test_pharmacy_lookup_is_not_repeated_per_request(): void
    {
        foreach (['/pharmacy/medicines', '/pharmacy/inventory'] as $url) {
            // مستخدم/صيدلية جديدة لكل طلب حتى لا يعاد استخدام العلاقة المثبّتة
            // من الطلب السابق على نفس الكائن (وهو ما يُفرغ الدلالة).
            [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
            $medicine = Medicine::factory()->create(['trade_name' => 'Panadol']);
            $this->attach($pharmacy, $medicine);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get($url)->assertOk();
            $sql = array_map(fn ($entry) => $entry['query'], DB::getQueryLog());
            DB::disableQueryLog();

            $pharmacyLookups = array_filter(
                $sql,
                fn ($query) => str_contains($query, 'from "pharmacies"')
                    || str_contains($query, 'from `pharmacies`')
            );

            $this->assertCount(
                1,
                $pharmacyLookups,
                "الصفحة {$url} يجب أن تُطلق استعلام صيدلية واحداً فقط (الوسيط يحمّلها). الفعلي: "
                    .count($pharmacyLookups).' → '.implode(' | ', $pharmacyLookups)
            );
        }
    }
}
