<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * القائمة الأساسية للأقسام — قابلة للتوسع من الـ Admin بدون تعديل كود.
     * البيانات الفعلية (17,295 صف) تحتوي Veterinary (310) وHerbal (3) — لذا مضمّنان.
     */
    public function run(): void
    {
        $categories = [
            ['name_ar' => 'أدوية', 'name_en' => 'Medicines', 'slug' => 'medicines', 'sort_order' => 1],
            ['name_ar' => 'العناية بالأسنان', 'name_en' => 'Dental Care', 'slug' => 'dental-care', 'sort_order' => 2],
            ['name_ar' => 'الإسعافات الأولية', 'name_en' => 'First Aid', 'slug' => 'first-aid', 'sort_order' => 3],
            ['name_ar' => 'الأم والطفل', 'name_en' => 'Mother & Baby', 'slug' => 'mother-baby', 'sort_order' => 4],
            ['name_ar' => 'العناية بالبشرة والجمال', 'name_en' => 'Skin Care & Beauty', 'slug' => 'skin-care-beauty', 'sort_order' => 5],
            ['name_ar' => 'مستلزمات طبية', 'name_en' => 'Medical Supplies', 'slug' => 'medical-supplies', 'sort_order' => 6],
            ['name_ar' => 'العيون', 'name_en' => 'Eye Care', 'slug' => 'eye-care', 'sort_order' => 7],
            ['name_ar' => 'الصحة والسلامة', 'name_en' => 'Health & Safety', 'slug' => 'health-safety', 'sort_order' => 8],
            ['name_ar' => 'الفيتامينات والمكملات', 'name_en' => 'Vitamins & Supplements', 'slug' => 'vitamins-supplements', 'sort_order' => 9],
            ['name_ar' => 'البيطرية', 'name_en' => 'Veterinary', 'slug' => 'veterinary', 'sort_order' => 10],
            ['name_ar' => 'الأعشاب الطبية', 'name_en' => 'Herbal', 'slug' => 'herbal', 'sort_order' => 11],
        ];

        foreach ($categories as $category) {
            // withTrashed: القيد unique يشمل المحذوف ناعماً، فإعادة البذر يجب أن
            // تستعيد القسم الافتراضي بدل محاولة إنشاء صف بنفس الـ slug.
            $existing = Category::withTrashed()
                ->where('slug', $category['slug'])
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                continue;
            }

            Category::create($category + ['is_active' => true]);
        }
    }
}
