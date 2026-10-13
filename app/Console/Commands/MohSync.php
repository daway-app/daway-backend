<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MohSync extends Command
{
    protected $signature = 'moh:sync {--timeout=180}';

    protected $description = 'مزامنة كتالوج أدوية وزارة الصحة الفلسطينية (المستحضرات المسجلة + قائمة الأسعار)';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $timeout = (int) $this->option('timeout');

        $this->info('جاري جلب المستحضرات المسجلة من وزارة الصحة...');
        $products = $this->fetchJson('https://pharmacy.moh.ps/service/getRegisterProducts', $timeout);
        if ($products === null) {
            $this->error('فشل جلب المستحضرات المسجلة.');
            return self::FAILURE;
        }

        // ── M-3B.2a: حاجز بنية الاستجابة ────────────────────────────────
        // HTTP 200 + JSON صالح **لا** دليل فيهما على اكتمال البيانات —
        // البنية يجب أن تطابق الشكل الذي يفهمه parser.
        if (! $this->hasAaData($products, 'المستحضرات المسجلة')) {
            return self::FAILURE;
        }

        $this->info('جاري جلب قائمة الأسعار...');
        $prices = $this->fetchJson('https://pharmacy.moh.ps/service/getDrugsPublic', $timeout);
        if ($prices === null) {
            $this->error('فشل جلب قائمة الأسعار.');
            return self::FAILURE;
        }

        if (! $this->hasAaData($prices, 'قائمة الأسعار')) {
            return self::FAILURE;
        }

        // ── M-3B.2a: عدّ الصفوف المرفوضة بدل تجاهلها بصمت ───────────────
        $skippedProducts = 0;
        $skippedPrices = 0;
        $productRows = $this->parseProducts($products['aaData'], $skippedProducts);
        $priceRows = $this->parsePrices($prices['aaData'], $skippedPrices);

        // ── M-3B.2a: حاجز المصدر الفارغ ─────────────────────────────────
        // 🔴 بدون هذا الحاجز: استجابة صحيحة نحويًا لكنها فارغة كانت ستمرّ
        // إلى delete-all + insert-la-shay ⇒ **كتالوج فارغ** + محو كل ما
        // يتعلق به عبر cascade/set null. لا يمكن إثبات سلامة مصدر فارغ.
        if ($productRows === []) {
            $this->error('الاستجابة لا تحتوي أي مستحضرات — أُوقفت المزامنة قبل أي كتابة للحفاظ على الكتالوج الحالي.');
            Log::error('moh:sync aborted: empty product source');

            return self::FAILURE;
        }

        if ($priceRows === []) {
            $this->error('الاستجابة لا تحتوي أي أسعار — أُوقفت المزامنة قبل أي كتابة (قائمة أسعار فارغة تُنتج كتالوجًا بلا مفاتيح moh_drug_id).');
            Log::error('moh:sync aborted: empty price source');

            return self::FAILURE;
        }

        if ($skippedProducts > 0 || $skippedPrices > 0) {
            $this->warn("تم تجاهل {$skippedProducts} صفًّا من المستحضرات و {$skippedPrices} صفًّا من الأسعار (بيانات ناقصة أو غير مفهومة).");
            Log::warning('moh:sync skipped malformed source rows', [
                'skipped_products' => $skippedProducts,
                'skipped_prices' => $skippedPrices,
            ]);
        }

        $this->info('تم جلب '.count($productRows).' مستحضر و '.count($priceRows).' دواء بالأسعار.');
        $this->info('جاري الدمج والتخزين...');

        $defaults = [
            'manufacturer' => null,
            'dosage_form' => null,
            'product_class' => null,
            'origin' => null,
            'moh_product_id' => null,
            'generic_name' => null,
            'official_price' => null,
            'packaging' => null,
            'company' => null,
            'availability' => null,
            'moh_drug_id' => null,
            'price_updated_at' => null,
        ];
        // ── M-3B.2b: بناء صفوف المصدر **كقائمة** لا كخريطة بالاسم ─────────
        // كان `$map[$name]` يطمس دواءين بنفس الاسم المطبَّع (آخر صفّ يفوز)
        // ⇒ اختفاء صامت لدواء مختلف له معرّف مختلف. القائمة تُبقي كل صفّ
        // مستقلًا، والاسم يُستخدم **فقط** لربط صفّ سعر بصفّ منتج واحد غير
        // مرتبط (ربط 1:1 = الانضمام الوحيد المتاح بين النداءين) — وليس كهوية.
        $rows = [];
        $pendingByProduct = [];

        foreach ($productRows as $row) {
            $key = $row['_key'];
            unset($row['_key']);
            $rows[] = array_merge($defaults, $row);
            $pendingByProduct[$key][] = count($rows) - 1;
        }
        unset($productRows);

        // M-3B.2b.1: استهلاك صفّ المنتج بحالة **مستقلة** عن `moh_drug_id`.
        // الاعتماد على `moh_drug_id !== null` كان يترك الصفّ «متاحًا» إذا لم
        // يستخرج `parsePrices` معرّف الدواء (فشل regex `DrugsId`) ⇒ صفّ سعر
        // ثانٍ يعيد استخدام نفس المنتج ويطمس حقوله.
        // M-3B.2b.2: عدّ صفوف الأسعار لكل اسم مطبَّع **قبل** الربط.
        // الالتباس خاصية لمجموعة الاسم (كم منتجًا وكم سعرًا يحملانه) لا للصفّ.
        $priceRowsByName = [];
        foreach ($priceRows as $row) {
            $priceRowsByName[$row['_key']] = ($priceRowsByName[$row['_key']] ?? 0) + 1;
        }

        $consumedProductRows = [];

        $merged = 0;
        $skippedAmbiguous = 0;

        foreach ($priceRows as $row) {
            $key = $row['_key'];
            unset($row['_key']);

            $candidates = $pendingByProduct[$key] ?? [];

            // لا منتج بهذا الاسم ⇒ سجلّ مستقل (لم يُتخذ أي قرار إسناد أصلًا)
            if ($candidates === []) {
                $rows[] = array_merge($defaults, $row);

                continue;
            }

            // 🔴 M-3B.2b.2: التباس — أكثر من منتج أو أكثر من سعر بنفس الاسم
            // المطبَّع، ولا هوية مشتركة موثوقة بين نداءَي MOH تثبت أيّ منتج
            // يقابل هذا السعر ⇒ **تخطّي الربط** بلا إسناد حسب ترتيب المصدر
            // وبلا توزيع تتابعي. الصفّ يُسقَط من خطة المصدر ولا يُنشأ سجلًا
            // مستقلًا (لتفادي تعارض «صفّان يستهدفان نفس السجل» مع كتالوج
            // سبق أن ربط المفتاحين)، وبياناته القائمة في الكتالوج لا تُمحى
            // لأن `updatePayload` يستبعد القيم الغائبة.
            if (count($candidates) > 1 || ($priceRowsByName[$key] ?? 0) > 1) {
                $skippedAmbiguous++;

                continue;
            }

            // واضح: منتج واحد وسعر واحد لهذا الاسم المطبَّع ⇒ ربط مؤكَّد
            $target = $candidates[0];

            // حارس دفاعي (M-3B.2b.1): لا يُستهلك صفّ منتج مرتين أبدًا
            if (isset($consumedProductRows[$target])) {
                $skippedAmbiguous++;

                continue;
            }

            $consumedProductRows[$target] = true;
            $rows[$target] = array_merge($rows[$target], $row);
            $merged++;
        }
        unset($priceRows);

        if ($skippedAmbiguous > 0) {
            $this->warn(
                "تم تخطّي ربط {$skippedAmbiguous} صفّ سعر لالتباس الاسم "
                .'(أكثر من منتج أو أكثر من سعر بنفس الاسم المطبَّع) — لم يُسنَد أي سعر تخمينًا.'
            );
            Log::warning('moh:sync: ambiguous product-price links skipped', [
                'skipped' => $skippedAmbiguous,
            ]);
        }

        // ── M-3B.2a: حاجز تعارض المفاتيح المستقرة داخل المصدر ───────────
        // الفهارس الفريدة (2026_09_23: moh_product_id/moh_drug_id) كانت ستُفشل
        // المعاملة **في منتصفها** لو تشارك اسمان مفتاحًا واحدًا — هنا نكشف
        // التعارض قبل أي كتابة فيصير الخطأ واضحًا والكتالوج محفوظًا.
        $duplicates = $this->duplicateStableKeys($rows);

        if ($duplicates !== []) {
            $this->error('تعارض مفاتيح وزارة الصحة داخل المصدر — لم تُكتب أي بيانات والكتالوج الحالي محفوظ.');
            foreach ($duplicates as $message) {
                $this->line(' - '.$message);
            }
            Log::error('moh:sync aborted: duplicate stable keys in source', [
                'duplicates' => $duplicates,
            ]);

            return self::FAILURE;
        }

        // ── M-3B.2b: قفل المزامنة ───────────────────────────────────────
        // driver الإنتاج `file` (render.yaml: CACHE_STORE=file) ⇒ FileStore::lock
        // يستخدم LockableFile (flock) وهو ذرّي **على مضيف واحد**. آمن للمثيل
        // الواحد (Render المجاني)؛ عند التوسّع لعدة مثيلات يجب نقل القفل إلى
        // مخزن مشترك (Redis/DB) وإلا لم يتنسّق المثيلان.
        $lock = Cache::lock('moh-sync', 900);

        if (! $lock->get()) {
            $this->error('مزامنة أخرى قيد التشغيل — أُوقفت هذه العملية دون أي كتابة.');
            Log::warning('moh:sync aborted: another sync holds the lock');

            return self::FAILURE;
        }

        try {
            // ── M-3B.2b: خطة المطابقة بالمفاتيح المستقرة (قبل أي كتابة) ──
            $resolution = $this->resolveUpsertPlan($rows);

            if ($resolution['conflicts'] !== []) {
                $this->error('تعارض في هوية الأدوية — لم تُكتب أي بيانات والكتالوج الحالي محفوظ.');
                foreach (array_slice($resolution['conflicts'], 0, 20) as $message) {
                    $this->line(' - '.$message);
                }
                if (count($resolution['conflicts']) > 20) {
                    $this->line('   … و'.(count($resolution['conflicts']) - 20).' تعارضًا آخر.');
                }
                Log::error('moh:sync aborted: identity conflicts', [
                    'conflicts' => count($resolution['conflicts']),
                    'samples' => array_slice($resolution['conflicts'], 0, 20),
                ]);

                return self::FAILURE;
            }

            $plan = $resolution['plan'];
            $updates = count(array_filter($plan, fn ($item) => $item['action'] === 'update'));
            $creates = count($plan) - $updates;

            // ── الكتابة: upsert موضعي **بلا أي حذف** ─────────────────────
            // لا `delete()`: المعرّفات الداخلية تبقى ⇒ لا cascade على
            // barcodes/images/reviews ولا قطع لربط مخزون الصيدليات.
            DB::transaction(function () use ($plan) {
                foreach ($plan as $item) {
                    if ($item['action'] === 'update') {
                        MohMedicine::whereKey($item['id'])->update($this->updatePayload($item['row']));
                    } else {
                        MohMedicine::create($item['row']);
                    }
                }
            });
        } finally {
            $lock->release();
        }

        unset($rows);

        // ── الغائب عن الاستجابة: يُبقى بلا تغيير ويُعَدّ فقط ──────────────
        // الغياب **ليس** دليلًا على إيقاف الدواء — لا حذف ولا تعطيل.
        $absent = $this->countAbsentFromSource($resolution['product_ids'], $resolution['drug_ids']);

        if ($absent > 0) {
            $this->warn("{$absent} سجلًا موجودًا لم يظهر في استجابة المصدر — أُبقيت بلا تغيير (الغياب ليس دليل إيقاف).");
            Log::info('moh:sync: records absent from source kept untouched', ['absent' => $absent]);
        }

        // إبطال الكاش المرتبط بكتالوج الوزارة بعد نجاح المزامنة
        Cache::add('med_catalog_version', 1, 3600 * 24 * 30);
        Cache::increment('med_catalog_version');
        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        $this->info(
            'تمت المزامنة: '.$updates.' محدَّث موضعيًا، '.$creates.' جديد، '
            .MohMedicine::count().' إجمالًا ('.$merged.' مدمج مع الأسعار).'
        );

        return self::SUCCESS;
    }

    /**
     * عميل HTTP لطلبَي وزارة الصحة — نقطة القرار الوحيدة لتحقّق SSL.
     *
     * M-3B.1: كان `fetchJson` يقرأ `env('MOH_SSL_VERIFY', false)` مباشرة
     * — و`config:cache` يجعل `env()` غير موثوق وقت التشغيل، والافتراضي
     * كان `false` (تعطيل). الآن القرار عبر `config()` والافتراضي **مُفعَّل**.
     *
     * fail-secure: أي قيمة مفقودة أو غير منطقية صريحة ⇒ **مُفعَّل**.
     * القيم الصريحة الوحيدة للتعطيل: 'false' · '0' · 'off' · 'no'.
     */
    public static function sslVerifyEnabled(): bool
    {
        $value = config('services.moh.ssl_verify', true);

        if ($value === null) {
            return true; // مفقود ⇒ مُفعَّل
        }

        if (is_bool($value)) {
            return $value;
        }

        // قيمة غير سلمية (مصفوفة/كائن) ⇒ إعداد تالف ⇒ **مُفعَّل** (fail-secure)
        if (! is_scalar($value)) {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        // القيم الصريحة الوحيدة للتعطيل — كل شيء آخر ⇒ مُفعَّل
        return ! in_array($normalized, ['0', 'false', 'off', 'no', ''], true);
    }

    /**
     * عميل HTTP مُهيَّأ لوزارة الصحة (مهلة + ترويسة + تحقّق SSL).
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    public static function mohHttpClient(int $timeout)
    {
        $request = Http::timeout($timeout)
            ->withHeaders(['Accept' => 'application/json']);

        return self::sslVerifyEnabled()
            ? $request
            : $request->withoutVerifying();
    }

    /**
     * M-3B.2b: بناء خطة المطابقة بالمفاتيح المستقرة **قبل أي كتابة**.
     *
     * قواعد الهوية:
     *   · المطابقة بـ`moh_product_id` في مجاله و`moh_drug_id` في مجاله — **لا بالاسم**.
     *   · المفتاحان موجودان ويشيران لسجلين مختلفين ⇒ تعارض.
     *   · السجل المطابق يحمل قيمة **مختلفة** لمفتاح مستقر آخر ⇒ تعارض (لا دمج، لا إعادة ربط).
     *   · صفّان مصدران يستهدفان نفس السجل ⇒ تعارض.
     *   · لا مطابقة ⇒ إنشاء سجل جديد.
     *   · مطابقة ⇒ تحديث موضعي يحفظ `moh_medicines.id`.
     *
     * @param  list<array<string,mixed>>  $rows  صفوف المصدر (قائمة — لا خريطة بالاسم)
     * @return array{plan:list<array{action:string,id?:int,row:array}>,conflicts:list<string>,product_ids:list<string>,drug_ids:list<string>}
     */
    private function resolveUpsertPlan(array $rows): array
    {
        $productIds = [];
        $drugIds = [];

        foreach ($rows as $row) {
            $productId = $row['moh_product_id'] ?? null;
            $drugId = $row['moh_drug_id'] ?? null;
            if ($productId !== null) {
                $productIds[(string) $productId] = true;
            }
            if ($drugId !== null) {
                $drugIds[(string) $drugId] = true;
            }
        }

        $byProduct = $this->loadExistingByKey('moh_product_id', array_keys($productIds));
        $byDrug = $this->loadExistingByKey('moh_drug_id', array_keys($drugIds));

        $plan = [];
        $conflicts = [];
        $plannedTargets = [];

        foreach ($rows as $row) {
            $name = (string) ($row['trade_name'] ?? '?');
            $productId = $row['moh_product_id'] ?? null;
            $drugId = $row['moh_drug_id'] ?? null;

            $matchProduct = $productId !== null ? ($byProduct[(string) $productId] ?? null) : null;
            $matchDrug = $drugId !== null ? ($byDrug[(string) $drugId] ?? null) : null;

            // المفتاحان يشيران إلى سجلين مختلفين
            if ($matchProduct !== null && $matchDrug !== null && $matchProduct['id'] !== $matchDrug['id']) {
                $conflicts[] = "المفتاحان يشيران لسجلين مختلفين: moh_product_id={$productId} ⇒ #{$matchProduct['id']} · moh_drug_id={$drugId} ⇒ #{$matchDrug['id']} [{$name}]";
                continue;
            }

            $target = $matchProduct ?? $matchDrug;

            if ($target !== null) {
                // تعارض بيانات: السجل المطابق يحمل قيمة مختلفة لمفتاح مستقر آخر
                if ($drugId !== null && $target['moh_drug_id'] !== null && (string) $target['moh_drug_id'] !== (string) $drugId) {
                    $conflicts[] = "تعارض بيانات: السجل #{$target['id']} يحمل moh_drug_id={$target['moh_drug_id']} والمصدر يقول {$drugId} [{$name}]";
                    continue;
                }
                if ($productId !== null && $target['moh_product_id'] !== null && (string) $target['moh_product_id'] !== (string) $productId) {
                    $conflicts[] = "تعارض بيانات: السجل #{$target['id']} يحمل moh_product_id={$target['moh_product_id']} والمصدر يقول {$productId} [{$name}]";
                    continue;
                }

                // صفّان مصدران يستهدفان نفس السجل ⇒ تعارض داخلي
                if (isset($plannedTargets[$target['id']])) {
                    $conflicts[] = "صفّان مصدران يستهدفان السجل #{$target['id']}: [{$plannedTargets[$target['id']]}] و [{$name}]";
                    continue;
                }

                $plannedTargets[$target['id']] = $name;
                $plan[] = ['action' => 'update', 'id' => $target['id'], 'row' => $row];

                continue;
            }

            $plan[] = ['action' => 'create', 'row' => $row];
        }

        return [
            'plan' => $plan,
            'conflicts' => $conflicts,
            'product_ids' => array_keys($productIds),
            'drug_ids' => array_keys($drugIds),
        ];
    }

    /**
     * فهرس السجلات الموجودة بمفتاح مستقر واحد (دفعات لتجنّب `IN` ضخم).
     *
     * @param  list<string>  $values
     * @return array<string,array{id:int,moh_product_id:mixed,moh_drug_id:mixed}>
     */
    private function loadExistingByKey(string $column, array $values): array
    {
        $index = [];

        foreach (array_chunk($values, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $rows = MohMedicine::query()
                ->whereIn($column, $chunk)
                ->get(['id', 'moh_product_id', 'moh_drug_id']);

            foreach ($rows as $row) {
                $index[(string) $row->{$column}] = [
                    'id' => (int) $row->id,
                    'moh_product_id' => $row->moh_product_id,
                    'moh_drug_id' => $row->moh_drug_id,
                ];
            }
        }

        return $index;
    }

    /**
     * M-3B.2b: حمولة التحديث — **لا تمسح قيمة قائمة بقيمة غائبة من المصدر**.
     *
     * المصدر قد لا يحمل سعرًا/شركةً لصفّ ما؛ تمرير `null` كان سيمحو بيانات
     * قائمة على السجل المطابق. لذلك تُستبعد القيم الفارغة، ولا يُكتب
     * `created_at`.
     *
     * ⚠️ الأثر المقصود: قيمة أزالها المصدر **لن** تُمحى من الكتالوج.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function updatePayload(array $row): array
    {
        $payload = [];

        foreach ($row as $key => $value) {
            if ($key === 'created_at' || $value === null) {
                continue;
            }

            $payload[$key] = $value;
        }

        $payload['updated_at'] = now();

        return $payload;
    }

    /**
     * M-3B.2b: عدّ السجلات الموجودة التي لم تظهر في استجابة المصدر.
     *
     * تُبقى بلا تغيير — الغياب ليس دليلًا على إيقاف الدواء أو حذفه.
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $drugIds
     */
    private function countAbsentFromSource(array $productIds, array $drugIds): int
    {
        $productSet = array_fill_keys($productIds, true);
        $drugSet = array_fill_keys($drugIds, true);
        $absent = 0;

        MohMedicine::query()
            ->select(['id', 'moh_product_id', 'moh_drug_id'])
            ->chunkById(1000, function ($rows) use ($productSet, $drugSet, &$absent) {
                foreach ($rows as $row) {
                    $productId = $row->moh_product_id;
                    $drugId = $row->moh_drug_id;

                    $inSource = ($productId !== null && isset($productSet[(string) $productId]))
                        || ($drugId !== null && isset($drugSet[(string) $drugId]));

                    if (! $inSource) {
                        $absent++;
                    }
                }
            });

        return $absent;
    }

    /**
     * M-3B.2a: كشف تعارض المفاتيح المستقرة داخل المصدر **قبل أي كتابة**.
     *
     * المفتاحان `moh_product_id` و`moh_drug_id` لكل منهما فهرس **UNIQUE**
     * على `moh_medicines` (ترحيل 2026_09_23). لو تشارك اسمان مفتاحًا واحدًا
     * لانتهكت المعاملة ذلك الفهرس في **منتصفها** بعد الحذف. هذا الفحص يحوّل
     * فشل قاعدة بيانات غامضًا إلى خطأ واضح يذكر الصفوف المتعارضة.
     *
     * @param  list<array<string,mixed>>  $rows  صفوف المصدر (قائمة)
     * @return list<string> رسائل التعارض (فارغة = لا تعارض)
     */
    private function duplicateStableKeys(array $rows): array
    {
        $productOwners = [];
        $drugOwners = [];
        $duplicates = [];

        foreach ($rows as $row) {
            $name = (string) ($row['trade_name'] ?? '?');
            $productId = $row['moh_product_id'] ?? null;
            if ($productId !== null) {
                if (array_key_exists($productId, $productOwners)) {
                    $duplicates[] = "moh_product_id {$productId} مكرر بين [{$productOwners[$productId]}] و [{$name}]";
                } else {
                    $productOwners[$productId] = $name;
                }
            }

            $drugId = $row['moh_drug_id'] ?? null;
            if ($drugId !== null) {
                if (array_key_exists($drugId, $drugOwners)) {
                    $duplicates[] = "moh_drug_id {$drugId} مكرر بين [{$drugOwners[$drugId]}] و [{$name}]";
                } else {
                    $drugOwners[$drugId] = $name;
                }
            }
        }

        return $duplicates;
    }

    /**
     * M-3B.2a: التحقق من بنية استجابة وزارة الصحة قبل أي معالجة.
     *
     * HTTP 200 + JSON صالح **لا** دليل فيهما على اكتمال البيانات — يجب أن
     * تحتوي `aaData` كمصفوفة وإلا فالشكل غير المفهوم للـparser.
     */
    private function hasAaData(mixed $response, string $label): bool
    {
        if (! is_array($response)
            || ! array_key_exists('aaData', $response)
            || ! is_array($response['aaData'])) {
            $this->error("استجابة غير متوقعة من وزارة الصحة ({$label}): بنية aaData مفقودة أو ليست مصفوفة — أُوقفت المزامنة قبل أي كتابة.");
            Log::error('moh:sync aborted: unexpected response structure', ['source' => $label]);

            return false;
        }

        return true;
    }

    private function fetchJson(string $url, int $timeout): ?array
    {
        try {
            $response = self::mohHttpClient($timeout)->get($url);

            if (! $response->successful()) {
                $this->error("خطأ HTTP {$response->status()} من $url");
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            $this->error('استثناء: '.$e->getMessage());
            return null;
        }
    }

    private function parseProducts(array $aaData, int &$skipped = 0): array
    {
        $rows = [];
        foreach ($aaData as $item) {
            if (! is_array($item) || count($item) < 5) {
                $skipped++;
                continue;
            }

            $name = $this->cleanText($item[0] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $productId = null;
            if (preg_match('/ProductsId\/(\d+)/', (string) ($item[5] ?? ''), $m)) {
                $productId = (int) $m[1];
            }

            $rows[] = [
                '_key' => $this->normalize($name),
                'trade_name' => mb_substr($name, 0, 255),
                'manufacturer' => $this->nullableText($item[1] ?? null),
                'dosage_form' => $this->nullableText($item[2] ?? null),
                'product_class' => $this->nullableText($item[3] ?? null),
                'origin' => $this->nullableText($item[4] ?? null),
                'moh_product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function parsePrices(array $aaData, int &$skipped = 0): array
    {
        $rows = [];
        foreach ($aaData as $item) {
            if (! is_array($item) || count($item) < 6) {
                $skipped++;
                continue;
            }

            $name = $this->cleanText($item[0] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $drugId = null;
            if (preg_match('/DrugsId\/(\d+)/', (string) ($item[6] ?? ''), $m)) {
                $drugId = (int) $m[1];
            }

            $updatedAt = null;
            if (preg_match("/flash'>(\d{4}-\d{2}-\d{2})/", (string) ($item[6] ?? ''), $m)) {
                $updatedAt = $m[1];
            }

            $price = null;
            $rawPrice = (string) ($item[2] ?? '');
            if (is_numeric($rawPrice)) {
                $price = (float) $rawPrice;
            }

            $rows[] = [
                '_key' => $this->normalize($name),
                'trade_name' => mb_substr($name, 0, 255),
                'generic_name' => $this->nullableText($item[1] ?? null),
                'official_price' => $price,
                'packaging' => $this->nullableText($item[3] ?? null),
                'company' => $this->nullableText($item[4] ?? null),
                'availability' => $this->nullableText($item[5] ?? null),
                'moh_drug_id' => $drugId,
                'price_updated_at' => $updatedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->cleanText((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function normalize(string $name): string
    {
        return mb_strtoupper($this->cleanText($name));
    }
}
