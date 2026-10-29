<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\User;
use App\Support\CategoryCatalogCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * لوحة الأدمن API — CRUD الأقسام (store / update / destroy).
 *
 * الخلفية: الـAPI كان يعرض index/toggle-status/links/attach/detach/review فقط،
 * وإنشاء الأقسام وتعديلها وحذفها كان **ويب-فقط** (`web\Admin\CategoryController`).
 * هذه القدرة حاجز حقيقي أمام حذف واجهة Blade.
 *
 * يحرس هذا الاختبار عقودًا قابلة للكسر الصامت:
 *
 *   1. **الحذف ناعم فقط.** قيد الـFK على `category_medicine_links` هو
 *      `cascadeOnDelete` لكنه لا يُفعَّل إلا عند `forceDelete()`؛ لو ظنّ أحدهم
 *      أن الحذف الناعم يُسلسل الحذف لضاعت روابط آلاف الأدوية.
 *
 *   2. **الـslug يُفحص مع المحذوف ناعمًا** (`withTrashed`) لأن الفهرس unique
 *      على مستوى الجدول. لو فُحص الحاضر فقط ⇒ خطأ تكرار عند إعادة استخدام اسم.
 *
 *   3. **`is_active` الغائبة على `update` لا تُعطّل القسم.** نموذج Blade يفرض
 *      القيمة دائمًا؛ طلب API ناقص الحقل يجب ألا يغيّر الحالة بصمت.
 *
 *   4. **كل كتابة تُبطل كاش كتالوج الأقسام** (`CategoryCatalogCache::bump()`).
 */
class CategoryCrudApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function category(string $nameEn = 'Antibiotics', array $overrides = []): Category
    {
        return Category::create(array_merge([
            'name_ar' => 'مضادات حيوية',
            'name_en' => $nameEn,
            'slug' => Str::slug($nameEn),
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    // ══════════════════════════════════════════════════════════════════
    // Create
    // ══════════════════════════════════════════════════════════════════

    public function test_admin_can_create_a_category_with_an_auto_slug(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [
                'name_ar' => 'مضادات حيوية',
                'name_en' => 'Antibiotics',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'antibiotics');

        $this->assertDatabaseHas('categories', [
            'name_en' => 'Antibiotics',
            'slug' => 'antibiotics',
        ]);
    }

    public function test_store_defaults_is_active_true_and_sort_order_zero_when_absent(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [
                'name_ar' => 'أ',
                'name_en' => 'Alpha',
            ])
            ->assertCreated();

        $category = Category::where('slug', 'alpha')->firstOrFail();

        $this->assertTrue($category->is_active, 'قسم جديد بلا is_active صريح يبدأ نشطًا.');
        $this->assertSame(0, $category->sort_order);
    }

    public function test_store_honours_an_explicit_slug(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [
                'name_ar' => 'أ',
                'name_en' => 'Alpha',
                'slug' => 'My Custom Slug',
                'is_active' => false,
                'sort_order' => 7,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'my-custom-slug');

        $category = Category::where('slug', 'my-custom-slug')->firstOrFail();

        $this->assertFalse($category->is_active);
        $this->assertSame(7, $category->sort_order);
    }

    public function test_store_rejects_missing_names(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar', 'name_en']);

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_duplicate_slug_gets_a_numeric_suffix(): void
    {
        $this->category('Antibiotics');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [
                'name_ar' => 'مضادات حيوية 2',
                'name_en' => 'Antibiotics',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'antibiotics-2');

        $this->assertSame(2, Category::where('name_en', 'Antibiotics')->count());
    }

    /** 🔴 الفهرس unique على مستوى الجدول ⇒ يجب فحص المحذوف ناعمًا أيضًا. */
    public function test_a_soft_deleted_slug_still_blocks_reuse(): void
    {
        $existing = $this->category('Antibiotics');
        $existing->delete();

        $this->assertSoftDeleted('categories', ['id' => $existing->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', [
                'name_ar' => 'مضادات حيوية',
                'name_en' => 'Antibiotics',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'antibiotics-2');
    }

    // ══════════════════════════════════════════════════════════════════
    // Update
    // ══════════════════════════════════════════════════════════════════

    public function test_admin_can_update_and_the_slug_regenerates_when_name_en_changes(): void
    {
        $category = $this->category('Antibiotics');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'مضادات فيروسية',
                'name_en' => 'Antivirals',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'antivirals');

        $category->refresh();
        $this->assertSame('Antivirals', $category->name_en);
        $this->assertSame('antivirals', $category->slug);
    }

    public function test_update_keeps_the_slug_when_name_en_is_unchanged(): void
    {
        $category = $this->category('Antibiotics');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'اسم عربي جديد',
                'name_en' => 'Antibiotics',
            ])
            ->assertOk();

        $this->assertSame('antibiotics', $category->fresh()->slug);
    }

    public function test_update_honours_an_explicit_slug(): void
    {
        $category = $this->category('Antibiotics');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'مضادات حيوية',
                'name_en' => 'Antibiotics',
                'slug' => 'custom-slug',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'custom-slug');

        $this->assertSame('custom-slug', $category->fresh()->slug);
    }

    /** 🔴 طلب API ناقص `is_active` يجب ألا يُعطّل قسمًا بصمت. */
    public function test_update_without_is_active_does_not_deactivate(): void
    {
        $category = $this->category('Antibiotics');

        $this->assertTrue($category->is_active);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'اسم محدَّث',
                'name_en' => 'Antibiotics',
            ])
            ->assertOk();

        $category->refresh();
        $this->assertTrue($category->is_active, 'الغياب يجب أن يعني «لا تغيير».');
        $this->assertSame(0, $category->sort_order, 'الترتيب أيضًا لا يُصفَّر عند الغياب.');
    }

    public function test_update_rejects_missing_names(): void
    {
        $category = $this->category('Antibiotics');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar', 'name_en']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Delete
    // ══════════════════════════════════════════════════════════════════

    /** 🔴 الحذف ناعم: روابط الأدوية تبقى (FK cascade لا يُفعَّل إلا بـforceDelete). */
    public function test_destroy_soft_deletes_the_category_and_keeps_its_links(): void
    {
        $category = $this->category('Antibiotics');

        $link = CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 987654,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('category_medicine_links', ['id' => $link->id]);
    }

    public function test_update_and_destroy_return_404_for_a_soft_deleted_category(): void
    {
        $category = $this->category('Antibiotics');
        $category->delete();

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'أ',
                'name_en' => 'Beta',
            ])
            ->assertStatus(404);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════════════
    // Cache
    // ══════════════════════════════════════════════════════════════════

    public function test_every_mutation_bumps_the_category_catalog_cache(): void
    {
        $category = $this->category('Antibiotics');
        $admin = $this->admin();

        $before = CategoryCatalogCache::version();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/categories', [
            'name_ar' => 'جديد',
            'name_en' => 'Beta',
        ])->assertCreated();

        $afterCreate = CategoryCatalogCache::version();
        $this->assertGreaterThan($before, $afterCreate, 'الإنشاء يجب أن يُبطل الكاش.');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/categories/{$category->id}", [
                'name_ar' => 'محدَّث',
                'name_en' => 'Antibiotics',
            ])
            ->assertOk();

        $afterUpdate = CategoryCatalogCache::version();
        $this->assertGreaterThan($afterCreate, $afterUpdate, 'التعديل يجب أن يُبطل الكاش.');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk();

        $this->assertGreaterThan($afterUpdate, CategoryCatalogCache::version(), 'الحذف يجب أن يُبطل الكاش.');
    }

    // ══════════════════════════════════════════════════════════════════
    // Authorization
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_mutate_categories(): void
    {
        $category = $this->category('Antibiotics');

        $this->postJson('/api/admin/categories', [
            'name_ar' => 'أ',
            'name_en' => 'Beta',
        ])->assertStatus(401);

        $this->putJson("/api/admin/categories/{$category->id}", [
            'name_ar' => 'أ',
            'name_en' => 'Beta',
        ])->assertStatus(401);

        $this->deleteJson("/api/admin/categories/{$category->id}")->assertStatus(401);

        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_mutate_categories(): void
    {
        $category = $this->category('Antibiotics');
        $pharmacy = $this->userWithRole('pharmacy');
        $patient = $this->userWithRole('patient');

        foreach ([$pharmacy, $patient] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->postJson('/api/admin/categories', [
                    'name_ar' => 'أ',
                    'name_en' => 'Beta',
                ])
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->putJson("/api/admin/categories/{$category->id}", [
                    'name_ar' => 'أ',
                    'name_en' => 'Beta',
                ])
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->deleteJson("/api/admin/categories/{$category->id}")
                ->assertStatus(403);
        }

        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }
}
