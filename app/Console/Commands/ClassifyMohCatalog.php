<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\MohMedicine;
use App\Support\MedicineNameMapper;
use Database\Seeders\CategorySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * تصنيف كتالوج وزارة الصحة (moh_medicines) وربطه بالأقسام في category_medicine_links.
 *
 * مصادر التصنيف (بالترتيب):
 *  STEP A — product_class مباشرة (source=product_class, confidence=95):
 *           Cosmetic Products → skin-care-beauty، Medical Devices → medical-supplies،
 *           Food Supplement → vitamins-supplements، Veterinary Products → veterinary،
 *           Herbal Products → herbal.
 *           Human Drug Products → medicines بثقة 90 (ثقة عالية بحد ذاتها) ثم يُطبَّق عليه STEP B أيضاً.
 *           'Recalls & Alerts' لا يُربط بأي قسم ويُحسب ضمن غير المصنّفين.
 *  STEP B — قواعد كلمات مفتاحية (source=rules, confidence=70) على النص المطبَّع
 *           (trade_name + generic_name + dosage_form + company + manufacturer):
 *           لاتيني بحدود كلمات \b لتفادي المطابقة داخل كلمات أخرى، وعربي بالاحتواء المباشر.
 *           + قاعدة "دواء بسعر رسمي": generic_name غير فارغ AND (official_price OR moh_drug_id)
 *             → medicines بثقة 80 (قائمة الأسعار = دليل قوي على قسم الأدوية).
 *           + قاعدة "موضعي جلدي": Human Drug Products + dosage_form (cream|ointment|lotion|gel)
 *             → skin-care-beauty بثقة 60 (رابط ثانوي — التعدد بين الأقسام مسموح).
 *
 * قواعد عامة:
 *  - المفاتيح المستقرة فقط: moh_product_id / moh_drug_id — يُمنع منعاً باتاً استخدام
 *    moh_medicines.id لأن moh:import / moh:sync يعيدان بناء الجدول (delete-all ثم insert).
 *  - الصف الذي يملك المفتاحين معاً يُخزَّن كرابط واحد بالعمودين معاً ليطابق البحث بأي اتجاه.
 *  - لا يُستخدم updateOrCreate الساذج على المفتاح المركّب: البحث بعمود NULL في MySQL لا يتعارض
 *    أبداً (NULL != NULL في الفهارس الفريدة)، فيُمكن أن يطابق أي رابط آخر بلا مفتاح في نفس القسم
 *    ويعدّله خطأً. لذا يُفحص أولاً وجود رابط في القسم بأيٍّ من المفتاحين، ثم يُحدَّث أو يُدرَج واحد فقط.
 *  - التعدد مسموح: الدواء الواحد قد يُربط بعدة أقسام، والقسم الواحد يقبل رابطاً واحداً لكل مفتاح
 *    (فهارس فريدة مركّبة: category_id+moh_product_id و category_id+moh_drug_id).
 *  - روابط admin مقدسة: لا تعديل ولا حذف ولا تخفيض ثقة — تُتخطى محاولات الكتابة فوقها.
 *    مع --fresh تُحذف الروابط التلقائية فقط (source != 'admin').
 *  - قابل للتكرار (idempotent): تشغيل متكرر على نفس البيانات لا يضيف تكرارات ولا يغيّر النتيجة.
 *
 * needs_review: تُفعَّل على روابط الصف إذا كان أعلى ثقة في تطابقاته أقل من 70
 * (أي لا يوجد إلا تطابقات ضعيفة) — يُربط مع تخطيطه للمراجعة البشرية.
 *
 * غير المصنّفين (قرار تصميمي — قائمة المراجعة):
 *  الصفوف التي لم تطابق أي قاعدة لا تُربط بأي قسم ولا يُخترع لها قسم، بل تُعرض في لوحة الأدمن
 *  عبر استعلام NOT EXISTS على الروابط (moh_product_id/moh_drug_id) — تبويب مستقل عن روابط
 *  needs_review=true. الإرفاق اليدوي من الأدمن يُنشئ رابطاً بـ source='admin' فيخرج الصف من القائمة.
 */
class ClassifyMohCatalog extends Command
{
    protected $signature = 'classify:moh-catalog
        {--fresh : إعادة التصنيف من الصفر (يحذف روابط المصدر التلقائي فقط، ويحافظ على روابط admin)}
        {--chunk=500 : عدد السجلات في كل دفعة معالجة}';

    protected $description = 'تصنيف كتالوج وزارة الصحة (moh_medicines) تلقائياً وربطه بالأقسام عبر product_class وقواعد الكلمات المفتاحية — قابل للتكرار، بلا تكرارات، ولا يمس روابط admin';

    /** خريطة product_class → قسم (تطابق حرفي بعد trim) */
    private const PRODUCT_CLASS_CATEGORIES = [
        'Cosmetic Products' => 'skin-care-beauty',
        'Medical Devices' => 'medical-supplies',
        'Food Supplement' => 'vitamins-supplements',
        'Veterinary Products' => 'veterinary',
        'Herbal Products' => 'herbal',
    ];

    private const HUMAN_DRUG_CLASS = 'Human Drug Products';
    private const RECALLS_CLASS = 'Recalls & Alerts';

    /** قواعد الكلمات المفتاحية — بالترتيب، أول تطابق لكل قسم (لكن القسم الواحد لا يُضاف مرتين للصف) */
    private const KEYWORD_RULES = [
        'eye-care' => [
            'latin' => '/\b(?:eyes?|ophthalmic|opti)/iu',
            'arabic' => ['عين', 'عيون'],
        ],
        'dental-care' => [
            'latin' => '/\b(?:dental|tooth|teeth|mouth|oral|gingiv)/iu',
            'arabic' => ['أسنان', 'فم', 'معجون'],
        ],
        'mother-baby' => [
            'latin' => '/\b(?:baby|infant|child|kids|pediatric|paediatric|pregnan|matern)/iu',
            'arabic' => ['طفل', 'أطفال', 'اطفال', 'حمل', 'رضاعة', 'رضيع'],
        ],
        'vitamins-supplements' => [
            'latin' => '/\b(?:vitamin|multivitamin|mineral|supplement|zinc|calcium|iron|omega|folic)/iu',
            'arabic' => ['فيتامين', 'مكمل', 'كالسيوم', 'أوميغا', 'اوميغا', 'زنك'],
        ],
        'first-aid' => [
            'latin' => '/\b(?:first\s+aid|antiseptic|disinfect|wound|bandage|gauze|plaster|dressing)/iu',
            'arabic' => ['إسعاف', 'اسعاف', 'ضماد', 'قطن طبي'],
        ],
        'health-safety' => [
            'latin' => '/\b(?:mask|sanitiz|steril|glove|protective)/iu',
            'arabic' => ['كمامة', 'كمامه', 'معقم', 'تعقيم', 'قفاز'],
        ],
    ];

    /** أشكال دوائية موضعية جلدية (رابط ثانوي لقسم البشرة للدواء البشري) */
    private const TOPICAL_DOSAGE_FORM_PATTERN = '/\b(?:cream|ointment|lotion|gel)\b/iu';

    private const CONF_PRODUCT_CLASS = 95;
    private const CONF_HUMAN_DRUG = 90;
    private const CONF_PRICE_LIST = 80;
    private const CONF_KEYWORD = 70;
    private const CONF_TOPICAL_SECONDARY = 60;
    private const CONF_REVIEW_THRESHOLD = 70;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $chunkSize = max(1, (int) $this->option('chunk'));
        $fresh = (bool) $this->option('fresh');

        try {
            return $this->classify($chunkSize, $fresh);
        } catch (\Throwable $e) {
            $this->error('فشل التصنيف: '.$e->getMessage());
            Log::error('classify:moh-catalog فشل: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    private function classify(int $chunkSize, bool $fresh): int
    {
        // ضمان وجود الأقسام الـ 11 بنفس قائمة CategorySeeder
        $this->call(CategorySeeder::class);

        $categories = Category::query()->orderBy('sort_order')->get(['id', 'slug', 'name_ar']);
        $categoryIds = $categories->pluck('id', 'slug')->all();

        $removedAuto = 0;
        if ($fresh) {
            $removedAuto = CategoryMedicineLink::query()->where('source', '!=', CategoryMedicineLink::SOURCE_ADMIN)->delete();
            $this->info("تم حذف {$removedAuto} رابط تلقائي (--fresh) — روابط admin محفوظة.");
        }
        $adminLinksPreserved = CategoryMedicineLink::query()->where('source', CategoryMedicineLink::SOURCE_ADMIN)->count();

        $total = MohMedicine::query()->count();
        $this->info("جاري تصنيف {$total} دواء من كتالوج وزارة الصحة...");

        $counters = [
            'processed' => 0,
            'linked' => 0,
            'unclassified' => 0,
            'needs_review' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_admin' => 0,
        ];
        $perCategory = [];
        $warnedSlugs = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        MohMedicine::query()->chunkById($chunkSize, function ($medicines) use (&$counters, &$perCategory, &$warnedSlugs, $categoryIds, $bar): void {
            foreach ($medicines as $medicine) {
                $counters['processed']++;

                $productId = $medicine->moh_product_id !== null ? (int) $medicine->moh_product_id : null;
                $drugId = $medicine->moh_drug_id !== null ? (int) $medicine->moh_drug_id : null;

                $candidates = $this->mergeCandidates($this->classifyRow($medicine));

                // بلا مفتاح مستقر لا يمكن إنشاء رابط يبقى صحيحاً بعد moh:sync — يُحسب غير مصنّف
                if ($candidates === [] || ($productId === null && $drugId === null)) {
                    $counters['unclassified']++;
                    $bar->advance();

                    continue;
                }

                // أعلى ثقة في تطابقات الصف تحدد needs_review لكل روابطه
                $maxConfidence = 0;
                foreach ($candidates as $candidate) {
                    $maxConfidence = max($maxConfidence, $candidate['confidence']);
                }
                $needsReview = $maxConfidence < self::CONF_REVIEW_THRESHOLD;

                $rowLinked = false;
                $rowHasAdmin = false;
                foreach ($candidates as $candidate) {
                    if (! array_key_exists($candidate['slug'], $categoryIds)) {
                        if (! in_array($candidate['slug'], $warnedSlugs, true)) {
                            $warnedSlugs[] = $candidate['slug'];
                            $this->warn("تحذير: القسم '{$candidate['slug']}' غير موجود في جدول categories — تم تجاهل روابطه.");
                        }

                        continue;
                    }

                    $result = $this->upsertLink(
                        (int) $categoryIds[$candidate['slug']],
                        $productId,
                        $drugId,
                        $candidate['source'],
                        $candidate['confidence'],
                        $needsReview
                    );

                    if ($result === 'admin') {
                        $counters['skipped_admin']++;
                        $rowHasAdmin = true;

                        continue;
                    }

                    $counters[$result === 'created' ? 'created' : 'updated']++;
                    $perCategory[$candidate['slug']] = ($perCategory[$candidate['slug']] ?? 0) + 1;
                    $rowLinked = true;
                }

                if ($rowLinked) {
                    $counters['linked']++;
                    if ($needsReview) {
                        $counters['needs_review']++;
                    }
                } elseif (! $rowHasAdmin) {
                    $counters['unclassified']++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // ملخص لكل قسم
        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [$category->name_ar, $perCategory[$category->slug] ?? 0];
        }
        $this->table(['القسم', 'الروابط'], $rows);

        $this->info("إجمالي السجلات المعالجة: {$counters['processed']}");
        $this->info("سجلات أُنشئت/حُدّثت روابطها: {$counters['linked']} (روابط جديدة: {$counters['created']}، محدّثة: {$counters['updated']})");
        $this->info("سجلات غير مصنّفة (بدون أي رابط — قائمة مراجعة الأدمن): {$counters['unclassified']}");
        $this->info("سجلات تحتاج مراجعة بشرية (needs_review): {$counters['needs_review']}");
        $this->info("محاولات كتابة تُم تخطّيها احتراماً لروابط admin: {$counters['skipped_admin']}");
        $this->info("روابط admin المحفوظة: {$adminLinksPreserved}");
        if ($fresh) {
            $this->info("روابط تلقائية حُذفت بسبب --fresh: {$removedAuto}");
        }

        Log::info('classify:moh-catalog انتهى', [
            'processed' => $counters['processed'],
            'linked' => $counters['linked'],
            'unclassified' => $counters['unclassified'],
            'needs_review' => $counters['needs_review'],
            'created' => $counters['created'],
            'updated' => $counters['updated'],
            'skipped_admin' => $counters['skipped_admin'],
            'removed_auto' => $removedAuto,
            'admin_preserved' => $adminLinksPreserved,
            'per_category' => $perCategory,
        ]);

        return self::SUCCESS;
    }

    /**
     * STEP A + STEP B: يعيد قائمة مرشّحين [slug, source, confidence] للصف الواحد
     * (قد تطابق الصف عدة أقسام مختلفة — التعدد مسموح).
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function classifyRow(MohMedicine $medicine): array
    {
        $candidates = [];
        $productClass = trim((string) $medicine->product_class);

        // STEP A — ربط مباشر حسب product_class
        if ($productClass === self::HUMAN_DRUG_CLASS) {
            // دواء بشري مسجَّل → قسم الأدوية بثقة عالية، مع متابعة STEP B (فيتامين أطفال مثلاً)
            $candidates[] = ['medicines', CategoryMedicineLink::SOURCE_PRODUCT_CLASS, self::CONF_HUMAN_DRUG];
        } elseif ($productClass === self::RECALLS_CLASS) {
            // سحب وتنبيهات → لا قسم، تُحسب ضمن غير المصنّفين
            return [];
        } elseif (isset(self::PRODUCT_CLASS_CATEGORIES[$productClass])) {
            $candidates[] = [self::PRODUCT_CLASS_CATEGORIES[$productClass], CategoryMedicineLink::SOURCE_PRODUCT_CLASS, self::CONF_PRODUCT_CLASS];
        }

        // STEP B — قواعد الكلمات المفتاحية
        $haystack = $this->buildHaystack($medicine);
        foreach (self::KEYWORD_RULES as $slug => $rule) {
            if ($this->ruleMatches($rule, $haystack)) {
                $candidates[] = [$slug, CategoryMedicineLink::SOURCE_RULES, self::CONF_KEYWORD];
            }
        }

        // دواء بسعر رسمي (صف قائمة الأسعار) → قسم الأدوية
        $genericName = trim((string) $medicine->generic_name);
        if ($genericName !== '' && ($medicine->official_price !== null || $medicine->moh_drug_id !== null)) {
            $candidates[] = ['medicines', CategoryMedicineLink::SOURCE_RULES, self::CONF_PRICE_LIST];
        }

        // شكل دوائي موضعي جلدي لدواء بشري → رابط ثانوي لقسم البشرة والجمال
        if ($productClass === self::HUMAN_DRUG_CLASS
            && preg_match(self::TOPICAL_DOSAGE_FORM_PATTERN, $this->normalize($medicine->dosage_form))) {
            $candidates[] = ['skin-care-beauty', CategoryMedicineLink::SOURCE_RULES, self::CONF_TOPICAL_SECONDARY];
        }

        return $candidates;
    }

    /** دمج المرشّحين: قسم واحد فقط لكل slug مع الاحتفاظ بأعلى ثقة (STEP A يتفوق على STEP B) */
    private function mergeCandidates(array $candidates): array
    {
        $best = [];
        foreach ($candidates as [$slug, $source, $confidence]) {
            if (! isset($best[$slug]) || $confidence > $best[$slug]['confidence']) {
                $best[$slug] = ['slug' => $slug, 'source' => $source, 'confidence' => $confidence];
            }
        }

        return array_values($best);
    }

    /** نص مطابقة موحّد: تنظيف التشكيل/التطويل + تصغير الأحرف، لكل الحقول الدالة على التصنيف */
    private function buildHaystack(MohMedicine $medicine): string
    {
        $fields = [
            $this->normalize($medicine->trade_name),
            $this->normalize($medicine->generic_name),
            $this->normalize($medicine->dosage_form),
            $this->normalize($medicine->company),
            $this->normalize($medicine->manufacturer),
        ];

        return implode(' ', array_filter($fields, fn (string $value): bool => $value !== ''));
    }

    private function ruleMatches(array $rule, string $haystack): bool
    {
        if (preg_match($rule['latin'], $haystack)) {
            return true;
        }

        foreach ($rule['arabic'] as $token) {
            if (mb_strpos($haystack, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        // clean() يزيل التشكيل والتطويل ويوحّد المسافات — يبقى تصغير حالة الأحرف اللاتينية
        return mb_strtolower(MedicineNameMapper::clean((string) $value));
    }

    /**
     * إدراج/تحديث رابط بشكل آمن مع الفهارس الفريدة المركّبة.
     *
     * - يفحص أولاً الروابط الموجودة في القسم بأيٍّ من المفتاحين (moh_product_id أو moh_drug_id).
     * - وجود رابط admin يطابق أي مفتاح → تخطٍّ كامل (لا تعديل ولا حذف).
     * - لا يوجد رابط → إدراج صف واحد بالعمودين معاً (إن وُجد المفتاحان).
     * - يوجد رابط (تشغيل سابق) → تحديثه وتعيين المفتاحين معاً، وحذف أي روابط تلقائية مكرّرة
     *   لنفس الثنائية قبل التحديث لتفادي تعارض الفهارس الفريدة.
     *
     * @return string 'created'|'updated'|'admin'
     */
    private function upsertLink(int $categoryId, ?int $productId, ?int $drugId, string $source, int $confidence, bool $needsReview): string
    {
        $existing = CategoryMedicineLink::query()
            ->where('category_id', $categoryId)
            ->where(function ($query) use ($productId, $drugId): void {
                if ($productId !== null) {
                    $query->orWhere('moh_product_id', $productId);
                }
                if ($drugId !== null) {
                    $query->orWhere('moh_drug_id', $drugId);
                }
            })
            ->get();

        // قرار admin لا يُمسّ: لا تعديل ولا حذف ولا تخفيض ثقة
        if ($existing->contains(fn (CategoryMedicineLink $link): bool => $link->source === CategoryMedicineLink::SOURCE_ADMIN)) {
            return 'admin';
        }

        $chosen = $existing->first(fn (CategoryMedicineLink $link): bool => $productId !== null && (int) $link->moh_product_id === $productId)
            ?? $existing->first(fn (CategoryMedicineLink $link): bool => $drugId !== null && (int) $link->moh_drug_id === $drugId)
            ?? $existing->first();

        if ($chosen === null) {
            CategoryMedicineLink::create([
                'category_id' => $categoryId,
                'moh_product_id' => $productId,
                'moh_drug_id' => $drugId,
                'source' => $source,
                'confidence' => $confidence,
                'needs_review' => $needsReview,
            ]);

            return 'created';
        }

        // تنظيف تكرارات قديمة لنفس الثنائية (غير admin) قبل التحديث
        $existing->reject(fn (CategoryMedicineLink $link): bool => $link->is($chosen))->each->delete();

        $chosen->source = $source;
        $chosen->confidence = $confidence;
        $chosen->needs_review = $needsReview;
        if ($productId !== null) {
            $chosen->moh_product_id = $productId;
        }
        if ($drugId !== null) {
            $chosen->moh_drug_id = $drugId;
        }
        $chosen->save();

        return 'updated';
    }
}
