<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SubcategorySeeder;
use Tests\TestCase;

/**
 * ترسيم فعلي لصفحة إدارة الأقسام.
 *
 * السبب: كل الاختبارات الموجودة تدقّ `route('categories.index')` بـassertRedirect
 * فقط — ولا واحد منها يرسم القالب. هذا بالضبط الفراغ الذي سمح لعطل تركيبي
 * (500 دائم) بأن يعيش شهوراً في قوالب أخرى. هنا نؤكد أن القالب يُرسم 200
 * وأن الأقسام الفرعية تظهر فعلاً في الصفحة.
 */
class CategoryIndexRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        $this->seed(SubcategorySeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_categories_index_renders_successfully_with_subcategories(): void
    {
        $response = $this->actingAs($this->admin())->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('فيتامينات الشعر', false);
        $response->assertSee('مكملات البروتين', false);
    }

    public function test_categories_index_renders_when_category_has_no_subcategories(): void
    {
        // قسم بلا أقسام فرعية (dental-care) ⇒ لا انفجار في القالب
        $response = $this->actingAs($this->admin())->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('العناية بالأسنان', false);
    }

    public function test_categories_index_renders_after_soft_deleting_all_subcategories(): void
    {
        Subcategory::query()->delete();

        $response = $this->actingAs($this->admin())->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('الفيتامينات والمكملات', false);
    }

    public function test_categories_index_hides_inactive_subcategories(): void
    {
        Subcategory::where('slug', 'hair-vitamins')->update(['is_active' => false]);

        $response = $this->actingAs($this->admin())->get(route('categories.index'));

        $response->assertOk();
        $response->assertDontSee('فيتامينات الشعر', false);
        $response->assertSee('مكملات البروتين', false);
    }

    public function test_category_show_page_renders(): void
    {
        // صفحة إدارة قسم واحد — كانت أيضاً بلا تغطية ترسيم
        $category = Category::where('slug', 'vitamins-supplements')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('categories.show', $category))
            ->assertOk();
    }
}
