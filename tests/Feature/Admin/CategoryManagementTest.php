<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function createCategory(array $attributes = []): Category
    {
        return Category::create($attributes + [
            'name_ar' => 'قسم تجريبي',
            'name_en' => 'Demo Category',
            'slug' => 'demo-category',
            'is_active' => true,
        ]);
    }

    private function createMohMedicine(array $attributes = []): MohMedicine
    {
        return MohMedicine::create($attributes + [
            'trade_name' => 'TESTMED 500mg',
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('categories.index'))->assertRedirect('/login');

        $this->post(route('categories.store'), ['name_ar' => 'x', 'name_en' => 'y'])
            ->assertRedirect('/login');
    }

    public function test_patient_and_pharmacy_are_redirected_away_from_admin_categories(): void
    {
        $this->actingAs(User::factory()->patient()->create());
        $this->get(route('categories.index'))->assertRedirect();

        $this->actingAs(User::factory()->pharmacy()->create());
        $category = $this->createCategory();
        $this->get(route('categories.show', $category))->assertRedirect();
        $this->post(route('categories.medicines.attach', $category), ['type' => 'moh', 'moh_product_id' => 1])
            ->assertRedirect();
        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_admin_can_create_category_with_auto_slug(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('categories.store'), [
            'name_ar' => 'مسكنات',
            'name_en' => 'Pain Relief Group',
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'name_ar' => 'مسكنات',
            'name_en' => 'Pain Relief Group',
            'slug' => 'pain-relief-group',
            'is_active' => true,
        ]);
    }

    public function test_store_defaults_sort_order_to_zero(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('categories.store'), [
            'name_ar' => 'ترتيب',
            'name_en' => 'Sort Order Cat',
        ])->assertRedirect(route('categories.index'));

        $category = Category::where('slug', 'sort-order-cat')->first();
        $this->assertNotNull($category);
        $this->assertSame(0, $category->sort_order);
    }

    public function test_store_validates_required_names(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('categories.create'))
            ->post(route('categories.store'), [])
            ->assertRedirect(route('categories.create'))
            ->assertSessionHasErrors(['name_ar', 'name_en']);

        $this->post(route('categories.store'), ['name_ar' => 'فقط عربي'])
            ->assertSessionHasErrors(['name_en']);

        $this->assertSame(0, Category::count());
    }

    public function test_admin_can_update_category_and_slug_is_regenerated_on_name_en_change(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory(['name_en' => 'Old Name', 'slug' => 'old-name']);

        $this->put(route('categories.update', $category), [
            'name_ar' => 'اسم جديد',
            'name_en' => 'New Name',
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $category->refresh();
        $this->assertSame('اسم جديد', $category->name_ar);
        $this->assertSame('New Name', $category->name_en);
        $this->assertSame('new-name', $category->slug);
    }

    public function test_update_keeps_slug_when_name_en_unchanged(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory(['name_en' => 'Same Name', 'slug' => 'same-name']);

        $this->put(route('categories.update', $category), [
            'name_ar' => 'عربي معدّل',
            'name_en' => 'Same Name',
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $this->assertSame('same-name', $category->refresh()->slug);
    }

    public function test_toggle_status_flips_is_active(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory(['is_active' => true]);

        $this->patch(route('categories.toggleStatus', $category))->assertRedirect();
        $this->assertFalse($category->refresh()->is_active);

        $this->patch(route('categories.toggleStatus', $category))->assertRedirect();
        $this->assertTrue($category->refresh()->is_active);
    }

    public function test_destroy_soft_deletes_category(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();

        $this->delete(route('categories.destroy', $category))->assertRedirect(route('categories.index'));

        $this->assertSoftDeleted($category);
        $this->assertSame(0, Category::count());
        $this->assertSame(1, Category::withTrashed()->count());
    }

    public function test_slug_uniqueness_gets_suffix_for_duplicate_names(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('categories.store'), [
            'name_ar' => 'فيتامينات',
            'name_en' => 'Vitamin Group',
        ])->assertRedirect();

        $this->post(route('categories.store'), [
            'name_ar' => 'فيتامينات ثانية',
            'name_en' => 'Vitamin Group',
        ])->assertRedirect();

        $slugs = Category::where('slug', 'like', 'vitamin-group%')->orderBy('id')->pluck('slug')->all();
        $this->assertSame(['vitamin-group', 'vitamin-group-2'], $slugs);
    }

    public function test_admin_can_attach_moh_medicine_by_stable_keys(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $this->createMohMedicine();

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'moh',
            'moh_product_id' => '1001',
            'moh_drug_id' => '5001',
        ])->assertRedirect();

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $category->id,
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
            'medicine_id' => null,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);
    }

    public function test_duplicate_attach_does_not_create_second_link(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $this->createMohMedicine();

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'moh',
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
        ])->assertRedirect();

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'moh',
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, CategoryMedicineLink::count());
    }

    public function test_admin_can_attach_local_medicine(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $medicine = Medicine::factory()->create(['trade_name' => 'LOCALMED 20']);

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'medicine',
            'medicine_id' => $medicine->id,
        ])->assertRedirect();

        $link = CategoryMedicineLink::first();
        $this->assertNotNull($link);
        $this->assertSame($medicine->id, $link->medicine_id);
        $this->assertNull($link->moh_product_id);
        $this->assertNull($link->moh_drug_id);
        $this->assertSame(CategoryMedicineLink::SOURCE_ADMIN, $link->source);
        $this->assertSame(100, $link->confidence);
    }

    public function test_attach_type_moh_without_any_moh_id_redirects_with_error(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'moh',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_attach_rejects_bogus_type(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();

        $this->post(route('categories.medicines.attach', $category), [
            'type' => 'bogus',
            'moh_product_id' => 1,
        ])->assertRedirect()->assertSessionHasErrors(['type']);

        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_detach_removes_link(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $link = CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 2002,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->delete(route('categories.medicines.detach', [$category->id, $link->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('category_medicine_links', ['id' => $link->id]);
    }

    public function test_detach_and_approve_reject_links_from_other_categories(): void
    {
        $this->actingAs($this->admin());
        $categoryA = $this->createCategory(['slug' => 'cat-a']);
        $categoryB = $this->createCategory(['name_ar' => 'قسم ب', 'name_en' => 'Cat B', 'slug' => 'cat-b']);
        $link = CategoryMedicineLink::create([
            'category_id' => $categoryB->id,
            'moh_product_id' => 3003,
            'source' => CategoryMedicineLink::SOURCE_RULES,
            'confidence' => 70,
            'needs_review' => true,
        ]);

        $this->delete(route('categories.medicines.detach', [$categoryA->id, $link->id]))->assertNotFound();
        $this->post(route('categories.review.approve', [$categoryA->id, $link->id]))->assertNotFound();

        $link->refresh();
        $this->assertTrue($link->needs_review);
    }

    public function test_approve_review_flips_needs_review_and_promotes_to_admin(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $link = CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 4004,
            'source' => CategoryMedicineLink::SOURCE_RULES,
            'confidence' => 70,
            'needs_review' => true,
        ]);

        $this->post(route('categories.review.approve', [$category->id, $link->id]))->assertRedirect();

        $link->refresh();
        $this->assertFalse($link->needs_review);
        $this->assertSame(CategoryMedicineLink::SOURCE_ADMIN, $link->source);
        $this->assertSame(100, $link->confidence);
    }

    public function test_show_page_displays_linked_medicine_name(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();
        $this->createMohMedicine(['trade_name' => 'SHOWMED 10mg', 'moh_product_id' => 4001]);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 4001,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->get(route('categories.show', $category))
            ->assertOk()
            ->assertSee('SHOWMED 10mg')
            ->assertSee($category->name_ar);
    }

    public function test_show_page_review_filter_shows_only_needs_review_links(): void
    {
        $this->actingAs($this->admin());
        $category = $this->createCategory();

        $this->createMohMedicine(['trade_name' => 'CLEANMED 1mg', 'moh_product_id' => 4002]);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 4002,
            'source' => CategoryMedicineLink::SOURCE_RULES,
            'confidence' => 80,
            'needs_review' => false,
        ]);

        $this->createMohMedicine(['trade_name' => 'REVIEWMED 2mg', 'moh_product_id' => 4003, 'moh_drug_id' => null]);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 4003,
            'source' => CategoryMedicineLink::SOURCE_RULES,
            'confidence' => 60,
            'needs_review' => true,
        ]);

        $this->get(route('categories.show', array_merge(['category' => $category->id], ['review' => 1])))
            ->assertOk()
            ->assertSee('REVIEWMED 2mg')
            ->assertDontSee('CLEANMED 1mg');
    }

    public function test_store_uploads_image_to_local_public_disk_when_cloudinary_disabled(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $this->post(route('categories.store'), [
            'name_ar' => 'قسم بصورة',
            'name_en' => 'Image Category',
            'image' => UploadedFile::fake()->image('cat.png'),
        ])->assertRedirect(route('categories.index'));

        $category = Category::where('slug', 'image-category')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->image);
        $this->assertStringStartsWith('categories/', $category->image);
        Storage::disk('public')->assertExists($category->image);
    }
}
