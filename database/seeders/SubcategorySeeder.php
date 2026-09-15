<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;

/**
 * الأقسام الفرعية — مطابقة لمجموعة الفلاتر في واجهة الموبايل.
 *
 * البيانات هنا هي "المصدر الوحيد" لأقسام الفلاتر الفرعية، وتُبذَر تلقائياً من
 * ClassifyMohCatalog قبل التصنيف (نفس نمط CategorySeeder)، فإعادة التصنيف على
 * بيئة جديدة لا تحتاج خطوة يدوية.
 *
 * group_key يجمع الأقسام الفرعية في صف عرض واحد:
 *  - vitamins    (الفيتامينات)
 *  - supplements (المكملات)
 * والأقسام غير المذكورة هنا (dental-care, eye-care ...) تبقى فلترة على مستوى
 * القسم الرئيسي فقط بلا أقسام فرعية — لا نخترع أقساماً لا طلبها المنتج.
 *
 * idempotent: تُستخدم withTrashed على slug الفريد، وإعادة البذر تُحيي القسم
 * الفرعي المحذوف ناعماً بدل محاولة إنشاء صف بنفس الـ slug.
 */
class SubcategorySeeder extends Seeder
{
    /**
     * @var array<int, array{category: string, name_ar: string, name_en: string, slug: string, group_key: string, sort_order: int}>
     */
    private const ROWS = [
        // الفيتامينات — القسم الرئيسي: الفيتامينات والمكملات
        ['category' => 'vitamins-supplements', 'name_ar' => 'فيتامينات الشعر', 'name_en' => 'Hair Vitamins', 'slug' => 'hair-vitamins', 'group_key' => 'vitamins', 'sort_order' => 1],
        ['category' => 'vitamins-supplements', 'name_ar' => 'فيتامينات البشرة', 'name_en' => 'Skin Vitamins', 'slug' => 'skin-vitamins', 'group_key' => 'vitamins', 'sort_order' => 2],
        ['category' => 'vitamins-supplements', 'name_ar' => 'فيتامينات المناعة', 'name_en' => 'Immunity Vitamins', 'slug' => 'immunity-vitamins', 'group_key' => 'vitamins', 'sort_order' => 3],
        ['category' => 'vitamins-supplements', 'name_ar' => 'فيتامينات العظام والمفاصل', 'name_en' => 'Bone & Joint Vitamins', 'slug' => 'bone-joint-vitamins', 'group_key' => 'vitamins', 'sort_order' => 4],

        // المكملات
        ['category' => 'vitamins-supplements', 'name_ar' => 'مكملات البروتين', 'name_en' => 'Protein Supplements', 'slug' => 'protein-supplements', 'group_key' => 'supplements', 'sort_order' => 1],
        ['category' => 'vitamins-supplements', 'name_ar' => 'مكملات الأوميغا', 'name_en' => 'Omega Supplements', 'slug' => 'omega-supplements', 'group_key' => 'supplements', 'sort_order' => 2],
        ['category' => 'vitamins-supplements', 'name_ar' => 'مكملات الحديد', 'name_en' => 'Iron Supplements', 'slug' => 'iron-supplements', 'group_key' => 'supplements', 'sort_order' => 3],
        ['category' => 'vitamins-supplements', 'name_ar' => 'مكملات الكالسيوم والمغنيسيوم', 'name_en' => 'Calcium & Magnesium Supplements', 'slug' => 'calcium-magnesium-supplements', 'group_key' => 'supplements', 'sort_order' => 4],
    ];

    public function run(): void
    {
        foreach (self::ROWS as $row) {
            $categoryId = Category::withTrashed()
                ->where('slug', $row['category'])
                ->value('id');

            // القسم الرئيسي غير موجود ⇒ CategorySeeder لم يُشغَّل بعد. لا نخترع
            // قسماً رئيسياً من هنا — نتركه لصاحب المسؤولية ونتخطى الصف.
            if ($categoryId === null) {
                continue;
            }

            $existing = Subcategory::withTrashed()->where('slug', $row['slug'])->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                // تصحيح الربط لو تغيّر القسم الرئيسي بين الإصدارات
                if ((int) $existing->category_id !== (int) $categoryId) {
                    $existing->category_id = $categoryId;
                    $existing->save();
                }

                continue;
            }

            Subcategory::create([
                'category_id' => $categoryId,
                'name_ar' => $row['name_ar'],
                'name_en' => $row['name_en'],
                'slug' => $row['slug'],
                'group_key' => $row['group_key'],
                'is_active' => true,
                'sort_order' => $row['sort_order'],
            ]);
        }
    }

    /**
     * قواعد ربط الأقسام الفرعية بصفوف كتالوج الوزارة.
     *
     * المفاتيح: slug القسم الفرعي ⇒ نمط لاتيني + كلمات عربية (نفس منهجية
     * ClassifyMohCatalog: لاتيني بحدود كلمات، عربي بالاحتواء المباشر).
     * 'exclude' : نمط يُلغي الربط إن طابق (يحرس من التطابقات الخاطئة).
     *
     * المستخدمة من ClassifyMohCatalog في دورة التصنيف نفسها، فلا يحتاج التصنيف
     * الفرعي تشغيلاً منفصلاً.
     *
     * @return array<string, array{latin: string, arabic: list<string>, exclude?: string}>
     */
    public static function matchRules(): array
    {
        return [
            'hair-vitamins' => [
                'latin' => '/\b(?:hair|biotin|keratin|follicle|scalp)\b/iu',
                'arabic' => ['الشعر', 'شعر', 'بيوتين', 'كيراتين'],
            ],
            'skin-vitamins' => [
                'latin' => '/\b(?:skin|derma|collagen|nail|nails)\b/iu',
                'arabic' => ['البشرة', 'بشرة', 'الكولاجين', 'كولاجين', 'الأظافر', 'الاظافر'],
            ],
            'immunity-vitamins' => [
                'latin' => '/\b(?:immun|vitamin\s*c|ascorbic|zinc|antioxidant)\b/iu',
                'arabic' => ['المناعة', 'مناعة', 'فيتامين سي', 'فيتامين ج', 'زنك'],
            ],
            'bone-joint-vitamins' => [
                'latin' => '/\b(?:bone|joint|cartilage|glucosamine|vitamin\s*d3?|cholecalciferol)\b/iu',
                'arabic' => ['العظام', 'عظام', 'المفاصل', 'مفاصل', 'فيتامين د', 'غضاريف'],
            ],
            'protein-supplements' => [
                'latin' => '/\b(?:protein|whey|casein|amino|creatine)\b/iu',
                'arabic' => ['البروتين', 'بروتين', 'واي', 'أحماض أمينية', 'احماض امينية'],
            ],
            'omega-supplements' => [
                'latin' => '/\b(?:omega|fish\s+oil|cod\s+liver|dha|epa|flaxseed)\b/iu',
                'arabic' => ['أوميغا', 'اوميغا', 'زيت السمك', 'سمك', 'أوميغا 3', 'اوميغا 3'],
            ],
            'iron-supplements' => [
                'latin' => '/\b(?:iron|ferrous|ferric|ferritin|folic)\b/iu',
                'arabic' => ['الحديد', 'حديد', 'فيروس', 'الفوليك', 'فوليك'],
            ],
            'calcium-magnesium-supplements' => [
                'latin' => '/\b(?:calcium|magnesium|\bmag\b)\b/iu',
                'arabic' => ['الكالسيوم', 'كالسيوم', 'المغنيسيوم', 'مغنيسيوم'],
            ],
        ];
    }
}
