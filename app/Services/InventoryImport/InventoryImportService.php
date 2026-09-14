<?php

namespace App\Services\InventoryImport;

use App\Contracts\FcmSender;
use App\Models\InventoryImport;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineAlias;
use App\Models\User;
use App\Services\MedicineCatalogService;
use App\Support\MedicineCatalogCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تنسيق عملية الاستيراد الجماعي للمخزون.
 *
 * التدفق: رفع → معاينة (بلا لمس المخزون) → قرارات → commit مقسّم.
 *
 * المبادئ الملزِمة:
 *  - لا كتابة على المخزون قبل الـ commit.
 *  - لا إنشاء دواء إلا بتأكيد صريح مخزّن على الخادم.
 *  - كل قرار من الواجهة يُعاد التحقق منه server-side.
 *  - الـ commit ذرّي لكل دفعة، ومحمي من التنفيذ المزدوج.
 *  - فشل الإشعار لا يُفشل الاستيراد.
 */
final class InventoryImportService
{
    /** الامتدادات المقبولة. */
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv'];

    /** أنواع MIME المقبولة (فحص إضافي فوق الامتداد). */
    private const ALLOWED_MIMES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly InventoryFileParser $parser,
        private readonly MedicineCatalogService $catalog,
        private readonly \App\Services\Ai\MedicineResolver $mapping,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | 1) الرفع
    |--------------------------------------------------------------------------
    */

    /**
     * تخزين الملف المرفوع بأمان وإنشاء جلسة استيراد بحالة uploaded.
     *
     * @throws ValidationException
     */
    public function createFromUpload(UploadedFile $file, User $user, Pharmacy $pharmacy): InventoryImport
    {
        $this->assertUploadIsSafe($file);

        $disk = (string) config('inventory_import.disk', 'local');
        $folder = trim((string) config('inventory_import.folder', 'inventory-imports'), '/');

        // اسم مولّد من الخادم — اسم المستخدم لا يدخل في المسار إطلاقاً
        $storedName = $folder.'/'.Str::uuid()->toString().'.'.$this->safeExtension($file);

        Storage::disk($disk)->put($storedName, $file->get());

        $absolutePath = Storage::disk($disk)->path($storedName);
        $hash = hash_file('sha256', $absolutePath);

        return InventoryImport::create([
            'pharmacy_id' => $pharmacy->id,
            'user_id' => $user->id,
            'original_filename' => $this->safeOriginalName($file),
            'file_hash' => $hash !== false ? $hash : '',
            'file_path' => $storedName,
            'status' => InventoryImport::STATUS_UPLOADED,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2) المعاينة (Dry-run) — لا تكتب أي شيء في المخزون
    |--------------------------------------------------------------------------
    */

    /**
     * تحليل الملف وحلّ الصفوف وبناء ملخّص المعاينة — بلا أي تعديل على المخزون.
     *
     * @throws ValidationException
     */
    public function preview(InventoryImport $import, Pharmacy $pharmacy): InventoryImport
    {
        $this->assertOpen($import);

        $parsed = $this->parser->parse($import->absoluteFilePath());

        $index = InventoryLookupIndex::build($pharmacy);
        $resolver = new InventoryRowResolver($index, $this->mapping);

        $rows = $resolver->resolve($parsed['rows']);

        $import->rows_payload = [
            'rows' => $rows,
            'groups' => $this->duplicateGroups($rows),
            'merges' => [],
            'unknown_columns' => $parsed['unknown_columns'],
        ];

        $import->status = InventoryImport::STATUS_PREVIEWED;
        $this->applySummary($import, $rows);

        $import->save();

        return $import;
    }

    /*
    |--------------------------------------------------------------------------
    | 3) قرارات المستخدم — كل قرار يُعاد التحقق منه
    |--------------------------------------------------------------------------
    */

    /**
     * تطبيق قرارات الصيدلي على صفوف المعاينة.
     *
     * @param  array<int, array{row:int, action:string, medicine_id?:int|null, moh_id?:int|null}>  $rowDecisions
     *         action=link   ⇒ medicine_id إلزامي (يُتحقّق من وجوده في الكتالوج).
     *         action=create ⇒ moh_id اختياري، ويجب أن يكون من اقتراحات الصف نفسه.
     * @param  array<string, string>  $mergeDecisions  مفتاح المجموعة => keep_last|keep_first|skip_all
     *
     * @throws ValidationException
     */
    public function applyDecisions(
        InventoryImport $import,
        Pharmacy $pharmacy,
        array $rowDecisions,
        array $mergeDecisions = [],
    ): InventoryImport {
        $this->assertOpen($import);

        if ($import->status === InventoryImport::STATUS_UPLOADED) {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_not_previewed'),
            ]);
        }

        $payload = (array) $import->rows_payload;
        $rows = (array) ($payload['rows'] ?? []);
        $groups = (array) ($payload['groups'] ?? []);

        // فهرس حديث من قاعدة البيانات — مرجع التحقق، وليس ما أرسلته الواجهة
        $index = InventoryLookupIndex::build($pharmacy);

        $byRowNumber = [];
        foreach ($rows as $i => $row) {
            $byRowNumber[(int) $row['row']] = $i;
        }

        // --- قرارات الدمج للمجموعات المكرّرة ---
        $merges = [];

        foreach ($mergeDecisions as $groupKey => $decision) {
            if (! isset($groups[$groupKey])) {
                continue;
            }

            if (! in_array($decision, [
                InventoryImport::MERGE_KEEP_LAST,
                InventoryImport::MERGE_KEEP_FIRST,
                InventoryImport::MERGE_SKIP_ALL,
            ], true)) {
                throw ValidationException::withMessages([
                    'merges' => __('pharmacy_import.error_invalid_merge'),
                ]);
            }

            $merges[$groupKey] = $decision;
        }

        // --- قرارات الصفوف ---
        foreach ($rowDecisions as $decision) {
            $rowNumber = (int) ($decision['row'] ?? 0);
            $action = (string) ($decision['action'] ?? '');

            if (! isset($byRowNumber[$rowNumber])) {
                // صف غير موجود في هذه الجلسة — نتجاهله بدل تخمين معناه
                continue;
            }

            if (! in_array($action, [
                InventoryImport::DECISION_LINK,
                InventoryImport::DECISION_CREATE,
                InventoryImport::DECISION_SKIP,
            ], true)) {
                throw ValidationException::withMessages([
                    'rows' => __('pharmacy_import.error_invalid_action'),
                ]);
            }

            $i = $byRowNumber[$rowNumber];

            if ($action === InventoryImport::DECISION_SKIP) {
                $rows[$i]['decision'] = InventoryImport::DECISION_SKIP;

                continue;
            }

            if ($action === InventoryImport::DECISION_LINK) {
                $medicineId = (int) ($decision['medicine_id'] ?? 0);

                // التحقق من وجود الدواء فعلاً في الكتالوج — لا نثق بالمعرّف القادم
                if ($index->medicineById($medicineId) === null) {
                    throw ValidationException::withMessages([
                        'rows' => __('pharmacy_import.error_unknown_medicine', ['row' => $rowNumber]),
                    ]);
                }

                $rows[$i]['decision'] = InventoryImport::DECISION_LINK;
                $rows[$i]['medicine_id'] = $medicineId;
                $rows[$i]['status'] = InventoryImport::ROW_EXACT_EN;
                $rows[$i]['match_method'] = 'manual';

                continue;
            }

            // action = create — يتطلّب أساساً صالحاً للإنشاء
            //
            // قد يأتي الإنشاء من اقتراح وزارة الصحة (اقتراح عرضه الخادم نفسه).
            // لا نقبل أي moh_id عابر: يجب أن يكون من اقتراحات هذا الصف بالذات،
            // وإلا صار بوسع الواجهة توجيه الإنشاء لأي عنصر في الكتالوج.
            $mohId = $decision['moh_id'] ?? null;

            if ($mohId !== null) {
                $mohId = (int) $mohId;

                $allowed = false;

                foreach ((array) ($rows[$i]['suggestions'] ?? []) as $suggestion) {
                    if ((int) ($suggestion['moh_id'] ?? 0) === $mohId) {
                        $allowed = true;

                        break;
                    }
                }

                if (! $allowed) {
                    throw ValidationException::withMessages([
                        'rows' => __('pharmacy_import.error_unknown_moh', ['row' => $rowNumber]),
                    ]);
                }

                $rows[$i]['proposed_moh_id'] = $mohId;
            }

            $this->assertRowCanBeCreated($rows[$i], $rowNumber);

            $rows[$i]['decision'] = InventoryImport::DECISION_CREATE;
        }

        $import->rows_payload = [
            'rows' => $rows,
            'groups' => $groups,
            'merges' => $merges,
            'unknown_columns' => $payload['unknown_columns'] ?? [],
        ];

        $import->status = InventoryImport::STATUS_PREVIEWED;
        $this->applySummary($import, $rows);

        if ($this->pendingRowNumbers($rows, $groups, $merges) === []) {
            $import->status = InventoryImport::STATUS_READY;
        }

        $import->save();

        return $import;
    }

    /*
    |--------------------------------------------------------------------------
    | 4) الـ Commit
    |--------------------------------------------------------------------------
    */

    /**
     * تنفيذ الاستيراد فعلياً — دفعات داخل transactions، بلا تنفيذ مزدوج.
     *
     * @return array<string, mixed> ملخّص التنفيذ
     *
     * @throws ValidationException
     */
    public function commit(InventoryImport $import, Pharmacy $pharmacy): array
    {
        $this->assertOpen($import);

        $payload = (array) $import->rows_payload;
        $rows = (array) ($payload['rows'] ?? []);
        $groups = (array) ($payload['groups'] ?? []);
        $merges = (array) ($payload['merges'] ?? []);

        $pending = $this->pendingRowNumbers($rows, $groups, $merges);

        if ($pending !== []) {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_pending_rows', ['count' => count($pending)]),
            ]);
        }

        // حجز ذرّي: من ينجح في تغيير الحالة يملك الحق في التنفيذ وحده.
        // يشمل التحقق من الانتهاء داخل نفس الجملة — لا فجوة بين الفحص والتنفيذ.
        $claimed = DB::table('inventory_imports')
            ->where('id', $import->id)
            ->where('pharmacy_id', $pharmacy->id)
            ->whereIn('status', [InventoryImport::STATUS_PREVIEWED, InventoryImport::STATUS_READY])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->update([
                'status' => InventoryImport::STATUS_COMMITTED,
                'committed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_already_committed'),
            ]);
        }

        $import->refresh();

        try {
            $result = $this->runCommit($import, $pharmacy, $rows, $groups, $merges);
        } catch (\Throwable $e) {
            // نُعيد الجلسة قابلة للمحاولة، ونسجّل التفاصيل داخلياً
            Log::error('inventory import commit failed', [
                'import_uuid' => $import->uuid,
                'pharmacy_id' => $pharmacy->id,
                'error' => $e->getMessage(),
            ]);

            $import->forceFill([
                'status' => InventoryImport::STATUS_READY,
                'committed_at' => null,
            ])->save();

            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_commit_failed'),
            ]);
        }

        $import->forceFill([
            'status' => InventoryImport::STATUS_COMMITTED,
            'committed_rows' => $result['committed_rows'],
            'created_medicines' => $result['created_medicines'],
            'commit_summary' => $result,
            'committed_at' => now(),
        ])->save();

        $this->deleteFile($import);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommit(
        InventoryImport $import,
        Pharmacy $pharmacy,
        array $rows,
        array $groups,
        array $merges,
    ): array {
        $plan = $this->buildPlan($rows, $groups, $merges);

        $chunkSize = max(1, (int) config('inventory_import.chunk_size', 500));
        $createdMedicines = 0;
        $committedRows = 0;
        $skippedRows = 0;

        // نحفظ (alias => medicine_id) لنتعلّمها بعد نجاح الدفعة فقط
        $aliasesToLearn = [];

        foreach (array_chunk($plan, $chunkSize, true) as $chunk) {
            $outcome = DB::transaction(function () use ($chunk, $pharmacy, &$aliasesToLearn) {
                $created = 0;
                $written = 0;

                $withMinStock = [];
                $withoutMinStock = [];
                $now = now();

                foreach ($chunk as $entry) {
                    $row = $entry['row'];

                    if ($entry['action'] === InventoryImport::DECISION_SKIP) {
                        continue;
                    }

                    $medicine = null;

                    if ($entry['action'] === InventoryImport::DECISION_CREATE) {
                        $medicine = $this->createMedicineForRow($row, $pharmacy);
                        $created++;
                    } else {
                        $medicineId = (int) $row['medicine_id'];

                        // تحقق أخير قبل الكتابة — الدواء قد يكون حُذف بين المعاينة والـ commit
                        if (! Medicine::whereKey($medicineId)->exists()) {
                            throw new \RuntimeException('medicine disappeared: '.$medicineId);
                        }
                    }

                    $medicineId = $medicine?->id ?? (int) $row['medicine_id'];
                    $input = (array) $row['input'];

                    $record = [
                        'pharmacy_id' => $pharmacy->id,
                        'medicine_id' => $medicineId,
                        'price' => (float) ($input['price'] ?? 0),
                        'quantity' => (int) ($input['quantity'] ?? 0),
                        'is_available' => (bool) ($input['is_available'] ?? false),
                        'min_stock' => $input['min_stock'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    // min_stock غائب في الملف ⇒ لا نكتبه إطلاقاً حتى لا نمحو قيمة موجودة بصمت
                    if ($record['min_stock'] === null) {
                        unset($record['min_stock']);
                        $withoutMinStock[] = $record;
                    } else {
                        $withMinStock[] = $record;
                    }

                    $written++;

                    // تعلّم المرادف: فقط عندما أكّد الصيدلي صراحةً أن كتابته تعني هذا الدواء
                    if ($entry['learn_alias'] !== null && $medicineId > 0) {
                        $aliasesToLearn[$entry['learn_alias']] = $medicineId;
                    }
                }

                if ($withMinStock !== []) {
                    PharmacyMedicine::upsert(
                        $withMinStock,
                        ['pharmacy_id', 'medicine_id'],
                        ['price', 'quantity', 'is_available', 'min_stock', 'updated_at']
                    );
                }

                if ($withoutMinStock !== []) {
                    PharmacyMedicine::upsert(
                        $withoutMinStock,
                        ['pharmacy_id', 'medicine_id'],
                        ['price', 'quantity', 'is_available', 'updated_at']
                    );
                }

                return ['created' => $created, 'written' => $written];
            });

            $createdMedicines += $outcome['created'];
            $committedRows += $outcome['written'];
            $skippedRows += count($chunk) - $outcome['written'];
        }

        // إبطال كاش الكتالوج فقط إذا أُنشئ دواء فعلاً — وإلا فالكاش سليم
        if ($createdMedicines > 0) {
            MedicineCatalogCache::bump();
        }

        $this->learnAliases($pharmacy, $aliasesToLearn);

        $summary = [
            'committed_rows' => $committedRows,
            'skipped_rows' => $skippedRows,
            'created_medicines' => $createdMedicines,
            'learned_aliases' => count($aliasesToLearn),
            'low_stock_rows' => $this->countLowStock($pharmacy),
        ];

        // إشعار واحد مجمّع، خارج الـ transaction، وفشله لا يُفشل الاستيراد
        $this->notifyImportFinished($pharmacy, $summary);

        return $summary;
    }

    /**
     * بناء خطة التنفيذ النهائية: كل صف → action + الدواء الهدف + هل نتعلّم مرادفاً.
     *
     * @return array<int, array{row: array<string, mixed>, action: string, learn_alias: ?string}>
     */
    private function buildPlan(array $rows, array $groups, array $merges): array
    {
        $plan = [];

        // الفائز في كل مجموعة مكرّرة (حسب قرار الدمج)
        $winnerRowNumber = [];

        foreach ($groups as $groupKey => $rowNumbers) {
            $decision = $merges[$groupKey] ?? InventoryImport::MERGE_KEEP_LAST;

            if ($decision === InventoryImport::MERGE_SKIP_ALL) {
                continue;
            }

            $numbers = array_map('intval', (array) $rowNumbers);

            $winnerRowNumber[$groupKey] = $decision === InventoryImport::MERGE_KEEP_FIRST
                ? min($numbers)
                : max($numbers);
        }

        foreach ($rows as $row) {
            $rowNumber = (int) $row['row'];
            $groupKey = $row['duplicate_group'] ?? null;

            if ($groupKey !== null && isset($groups[$groupKey])) {
                // صف مكرّر: لا يدخل إلا إذا كان الفائز في مجموعته
                if (($winnerRowNumber[$groupKey] ?? null) !== $rowNumber) {
                    $plan[] = [
                        'row' => $row,
                        'action' => InventoryImport::DECISION_SKIP,
                        'learn_alias' => null,
                    ];

                    continue;
                }
            }

            if ($row['status'] === InventoryImport::ROW_INVALID) {
                $plan[] = [
                    'row' => $row,
                    'action' => InventoryImport::DECISION_SKIP,
                    'learn_alias' => null,
                ];

                continue;
            }

            $action = (string) ($row['decision'] ?? InventoryImport::DECISION_SKIP);

            if (! in_array($action, [InventoryImport::DECISION_LINK, InventoryImport::DECISION_CREATE], true)) {
                $plan[] = ['row' => $row, 'action' => InventoryImport::DECISION_SKIP, 'learn_alias' => null];

                continue;
            }

            // نتعلّم المرادف فقط عند تأكيد يدوي صريح (لا من مطابقة تلقائية ولا fuzzy)
            $learnAlias = null;

            if (($row['match_method'] ?? null) === 'manual') {
                $learnAlias = $this->aliasKeyFor($row);
            }

            $plan[] = ['row' => $row, 'action' => $action, 'learn_alias' => $learnAlias];
        }

        return $plan;
    }

    /**
     * إنشاء دواء جديد — فقط بعد تأكيد صريح محفوظ على الخادم.
     * يستخدم MedicineCatalogService القائم، ولا يكرّر منطق الكتالوج.
     */
    private function createMedicineForRow(array $row, Pharmacy $pharmacy): Medicine
    {
        $input = (array) $row['input'];
        $tradeName = trim((string) ($input['trade_name'] ?? ''));
        $tradeNameAr = $input['trade_name_ar'] ?? null;

        // (أ) من كتالوج وزارة الصحة — مصدر رسمي
        if (! empty($row['proposed_moh_id'])) {
            $moh = MohMedicine::find((int) $row['proposed_moh_id']);

            if ($moh) {
                // منع التكرار: قد يكون أُنشئ بين المعاينة والـ commit
                $existing = $this->catalog->resolveByName($moh->trade_name, null, lookupMoh: false);

                return $existing ?? $this->catalog->findOrCreateFromMoh($moh);
            }
        }

        // (ب) من مدخلات الصيدلي — يتطلّب مادة فعالة
        $activeIngredient = trim((string) ($input['active_ingredient'] ?? ''));

        if ($tradeName === '' || $activeIngredient === '') {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_create_needs_ingredient', ['row' => $row['row']]),
            ]);
        }

        // منع إنشاء دواء مكرّر: إعادة التحقق من التفرّد قبل الإنشاء
        $existing = $this->catalog->resolveByName($tradeName, $tradeNameAr, lookupMoh: false);

        if ($existing) {
            return $existing;
        }

        return $this->catalog->createFromNames($tradeName, $tradeNameAr, $activeIngredient);
    }

    /** حفظ المرادفات المؤكَّدة — بصمت في حال التعارض (لا نكسر العملية). */
    private function learnAliases(Pharmacy $pharmacy, array $aliasesToLearn): void
    {
        foreach ($aliasesToLearn as $alias => $medicineId) {
            try {
                PharmacyMedicineAlias::updateOrCreate(
                    ['pharmacy_id' => $pharmacy->id, 'alias' => $alias],
                    ['medicine_id' => $medicineId]
                );
            } catch (\Throwable $e) {
                Log::warning('inventory import alias learn failed', [
                    'pharmacy_id' => $pharmacy->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** إشعار واحد مجمّع — منفصل تماماً عن المعاملة، وفشله لا يُفشل الاستيراد. */
    private function notifyImportFinished(Pharmacy $pharmacy, array $summary): void
    {
        try {
            $user = $pharmacy->user;

            if (! $user) {
                return;
            }

            $notification = Notification::create([
                'user_id' => $user->id,
                'medicine_id' => null,
                'type' => 'inventory_import',
                'message' => __('pharmacy_import.notif_import_finished', [
                    'count' => $summary['committed_rows'],
                    'low' => $summary['low_stock_rows'],
                ]),
                'is_read' => false,
                'created_at' => now(),
            ]);

            app(FcmSender::class)->fromNotification($notification);
        } catch (\Throwable $e) {
            Log::warning('inventory import notification failed', [
                'pharmacy_id' => $pharmacy->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** عدد أصناف المخزون الواقعة تحت حد النقص — استعلام واحد، بلا تحميل صفوف. */
    private function countLowStock(Pharmacy $pharmacy): int
    {
        return PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('quantity', '<=', PharmacyMedicine::LOW_STOCK_THRESHOLD)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | 5) حالة الجلسة (للقراءة فقط — تستهلكها الواجهة)
    |--------------------------------------------------------------------------
    */

    /**
     * عدد الأسطر التي ما زالت تمنع التنفيذ.
     *
     * مصدر واحد للحقيقة: نفس الدالة التي يستخدمها commit لرفض التنفيذ،
     * فتستحيل مفارقة "الواجهة تقول جاهز والـ commit يرفض".
     */
    public function pendingCount(InventoryImport $import): int
    {
        $payload = (array) $import->rows_payload;

        return count($this->pendingRowNumbers(
            (array) ($payload['rows'] ?? []),
            (array) ($payload['groups'] ?? []),
            (array) ($payload['merges'] ?? []),
        ));
    }

    /**
     * ملخّص قرارات قابل للعرض: ماذا سيكتب فعلاً لو ضغط الصيدلي "تنفيذ" الآن.
     *
     * @return array{pending: int, to_link: int, to_create: int, to_skip: int, ready: bool}
     */
    public function decisionSummary(InventoryImport $import): array
    {
        $rows = $import->rows();
        $groups = $import->duplicateGroups();
        $merges = $import->mergeDecisions();

        $pending = $this->pendingRowNumbers($rows, $groups, $merges);
        $pendingSet = array_flip($pending);

        $toLink = 0;
        $toCreate = 0;
        $toSkip = 0;

        $winnerRowNumber = [];

        foreach ($groups as $groupKey => $rowNumbers) {
            $decision = $merges[$groupKey] ?? null;

            if ($decision === null || $decision === InventoryImport::MERGE_SKIP_ALL) {
                continue;
            }

            $numbers = array_map('intval', (array) $rowNumbers);

            $winnerRowNumber[$groupKey] = $decision === InventoryImport::MERGE_KEEP_FIRST
                ? min($numbers)
                : max($numbers);
        }

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['row'] ?? 0);

            if (isset($pendingSet[$rowNumber])) {
                continue;
            }

            // صف مكرّر ليس الفائز في مجموعته → لن يُكتب
            $groupKey = $row['duplicate_group'] ?? null;

            if ($groupKey !== null && isset($groups[$groupKey])
                && ($winnerRowNumber[$groupKey] ?? null) !== $rowNumber) {
                $toSkip++;

                continue;
            }

            match ((string) ($row['decision'] ?? InventoryImport::DECISION_SKIP)) {
                InventoryImport::DECISION_CREATE => $toCreate++,
                InventoryImport::DECISION_LINK => $toLink++,
                default => $toSkip++,
            };
        }

        return [
            'pending' => count($pending),
            'to_link' => $toLink,
            'to_create' => $toCreate,
            'to_skip' => $toSkip,
            'ready' => $pending === [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 6) إلغاء الجلسة
    |--------------------------------------------------------------------------
    */

    public function cancel(InventoryImport $import): void
    {
        if ($import->isCommitted()) {
            return;
        }

        $this->deleteFile($import);

        $import->forceFill(['status' => InventoryImport::STATUS_EXPIRED])->save();
    }

    /** حذف الملف المؤقت — بصمت، فشله لا يعطّل شيئاً. */
    private function deleteFile(InventoryImport $import): void
    {
        try {
            Storage::disk((string) config('inventory_import.disk', 'local'))->delete($import->file_path);
        } catch (\Throwable $e) {
            Log::warning('inventory import temp file delete failed', [
                'import_uuid' => $import->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | مساعدات
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>>
     */
    private function duplicateGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['duplicate_group'] ?? null;

            if ($key !== null) {
                $groups[$key][] = (int) $row['row'];
            }
        }

        return $groups;
    }

    /**
     * الصفوف التي تمنع الـ commit.
     *
     * صف مكرّر لا يُحاسب على قراره الفردي — قراره محكوم بقرار الدمج،
     * لكن الفائز في المجموعة يجب أن يملك قراراً نهائياً صالحاً.
     *
     * @return array<int, int>
     */
    private function pendingRowNumbers(array $rows, array $groups, array $merges): array
    {
        $pending = [];

        // مجموعات بلا قرار دمج → كل صفوفها معلّقة
        foreach ($groups as $groupKey => $rowNumbers) {
            if (! isset($merges[$groupKey])) {
                foreach ((array) $rowNumbers as $rowNumber) {
                    $pending[] = (int) $rowNumber;
                }
            }
        }

        // الفائز في كل مجموعة لها قرار دمج — يجب أن يملك قراراً نهائياً
        $winnerRowNumber = [];

        foreach ($groups as $groupKey => $rowNumbers) {
            $decision = $merges[$groupKey] ?? null;

            if ($decision === null || $decision === InventoryImport::MERGE_SKIP_ALL) {
                continue;
            }

            $numbers = array_map('intval', (array) $rowNumbers);

            $winnerRowNumber[$groupKey] = $decision === InventoryImport::MERGE_KEEP_FIRST
                ? min($numbers)
                : max($numbers);
        }

        foreach ($rows as $row) {
            if ($row['status'] === InventoryImport::ROW_INVALID) {
                continue;
            }

            $rowNumber = (int) $row['row'];
            $groupKey = $row['duplicate_group'] ?? null;

            if ($groupKey !== null && isset($groups[$groupKey])) {
                // ليس الفائز، أو المجموعة مُتجاهَلة بالكامل → لا يمنع
                if (($winnerRowNumber[$groupKey] ?? null) !== $rowNumber) {
                    continue;
                }
            }

            $decision = (string) ($row['decision'] ?? InventoryImport::DECISION_PENDING);

            if (! in_array($decision, [
                InventoryImport::DECISION_LINK,
                InventoryImport::DECISION_CREATE,
                InventoryImport::DECISION_SKIP,
            ], true)) {
                $pending[] = $rowNumber;
            }
        }

        return array_values(array_unique($pending));
    }

    /**
     * تحديث عدّادات الملخّص من حالات الصفوف.
     * الحالات حصرية، فالمجموعات منفصلة بلا ازدواج.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function applySummary(InventoryImport $import, array $rows): void
    {
        $counts = [
            'matched' => 0,
            'review' => 0,
            'unmatched' => 0,
            'duplicate' => 0,
            'error' => 0,
        ];

        foreach ($rows as $row) {
            $counts[match ($row['status']) {
                InventoryImport::ROW_EXACT_EN,
                InventoryImport::ROW_EXACT_AR,
                InventoryImport::ROW_ALIAS => 'matched',
                InventoryImport::ROW_MOH,
                InventoryImport::ROW_FUZZY,
                InventoryImport::ROW_REVIEW => 'review',
                InventoryImport::ROW_UNMATCHED => 'unmatched',
                InventoryImport::ROW_DUPLICATE => 'duplicate',
                default => 'error',
            }]++;
        }

        $import->total_rows = count($rows);
        $import->matched_rows = $counts['matched'];
        $import->review_rows = $counts['review'];
        $import->unmatched_rows = $counts['unmatched'];
        $import->duplicate_rows = $counts['duplicate'];
        $import->error_rows = $counts['error'];
    }

    /**
     * مفتاح المرادف المحفوظ: الاسم الإنجليزي المُدخل كما كتبه الصيدلي، مطبّعاً.
     * نستخدم الاسم الإنجليزي لأنه مفتاح المطابقة الأساسي في كل المسارات.
     */
    private function aliasKeyFor(array $row): ?string
    {
        $key = InventoryLookupIndex::normalizeEn($row['input']['trade_name'] ?? null);

        return $key !== '' ? mb_substr($key, 0, 191) : null;
    }

    /** @throws ValidationException */
    private function assertRowCanBeCreated(array $row, int $rowNumber): void
    {
        if (($row['errors'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'rows' => __('pharmacy_import.error_invalid_row', ['row' => $rowNumber]),
            ]);
        }

        // مسموح: عنصر كتالوج وزارة الصحة، أو إنشاء صريح بمادة فعالة معلنة
        if (! empty($row['proposed_moh_id'])) {
            return;
        }

        $input = (array) $row['input'];

        if (trim((string) ($input['trade_name'] ?? '')) === ''
            || trim((string) ($input['active_ingredient'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'rows' => __('pharmacy_import.error_create_needs_ingredient', ['row' => $rowNumber]),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertOpen(InventoryImport $import): void
    {
        if ($import->isCommitted()) {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_already_committed'),
            ]);
        }

        if ($import->isExpired()) {
            throw ValidationException::withMessages([
                'import' => __('pharmacy_import.error_expired'),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertUploadIsSafe(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_upload_failed'),
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_bad_extension', [
                    'allowed' => implode(', ', self::ALLOWED_EXTENSIONS),
                ]),
            ]);
        }

        $maxKb = (int) config('inventory_import.max_file_kb', 5120);

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_file_too_large', [
                    'max' => round($maxKb / 1024, 1),
                ]),
            ]);
        }

        $mime = (string) $file->getMimeType();

        if ($mime !== '' && ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_bad_mime'),
            ]);
        }
    }

    /** الامتداد المطبّع — قيمة من قائمة بيضاء دائماً. */
    private function safeExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'xlsx';
    }

    /** اسم العرض فقط — تُزال منه أي فواصل مسارات. */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();
        $name = str_replace(['/', '\\', "\0"], '', $name);
        $name = trim($name);

        return $name === '' ? 'inventory' : mb_substr($name, 0, 255);
    }
}
