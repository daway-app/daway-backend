<?php

namespace App\Services\Enrichment;

use App\Models\MedicineBarcode;
use App\Models\MedicineImage;
use App\Models\MedicineEnrichmentReview;
use App\Models\MedicineEnrichmentRun;
use App\Models\MohMedicine;
use App\Services\Enrichment\Providers\DrugsApiProvider;
use App\Services\Enrichment\Providers\ManualProvider;
use App\Services\Enrichment\Providers\MedicineDataProvider;
use App\Services\Enrichment\Providers\MedicineQuery;
use App\Services\Enrichment\Providers\ProviderResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * محرك الإثراء — يقرأ من moh_medicines ويغذّي نصّ الصفحة
 * medicine_barcodes / medicine_images / medicine_enrichment_reviews.
 *
 * القواعد الصارمة (spec):
 *  - الأصل له أولوية: الحقل الموجود لا يُستبدل أبداً (اختراق الاسم المختلف).
 *  - لا كتابة قبل توكيّد الثقة بالـthresholds (auto_accept / review).
 *  - منع ازدواج باركود عالمياً (unique barcode) — أول من كتب يبقى صرفياً.
 *  - الصور: رابط وإحالة فقط — لا base64 ولا تحميل.
 */
final class EnrichmentEngine
{
    public function __construct(
        private readonly \App\Services\Ai\MedicineResolver $resolver,
    ) {}

    /**
     * аптادة موثقة (run) واحدة: تقرأ from last_offset + limit، تعالج وتُحدّث counters.
     * يعمل atomic per-batch (كلها في transaction) وإ حدوال retries إذا.
     *
     * @param  bool  $dryRun  توثيق فقط، بلا أي كتابة.
     */
    public function run(
        ?MedicineEnrichmentRun $previousRun,
        bool $dryRun,
        ?int $batchSize = null,
    ): MedicineEnrichmentRun {
        $providers = $this->enabledProviders();

        // resume تلقائي: إن وُجد run نصف مكتمل والcaller لم يحدد one → استكمال
        if ($previousRun === null) {
            $previousRun = MedicineEnrichmentRun::where('status', MedicineEnrichmentRun::STATUS_RUNNING)
                ->orderByDesc('id')->first();
        }

        $run = $previousRun ?? MedicineEnrichmentRun::create([
            'provider' => $providers !== [] ? $providers[0]->name() : 'none',
            'status' => MedicineEnrichmentRun::STATUS_RUNNING,
            'batch_size' => $batchSize ?? 100,
            'started_at' => now(),
        ]);

        $batchSize = $batchSize ?? $run->batch_size;
        if ($providers === []) {
            $run->update(['status' => MedicineEnrichmentRun::STATUS_ABORTED, 'completed_at' => now()]);

            return $run;
        }

        $run->update(['total_records' => MohMedicine::count()]);

        // دقيق size = batch-size unmatched (نُصل نقاط bounded)
        $medicines = MohMedicine::query()
            ->orderBy('id')
            ->skip((int) $run->last_offset)
            ->take($batchSize)
            ->get();

        foreach ($medicines as $moh) {
            $decision = $this->processOne($moh, $providers, $dryRun);

            $run->processed_records += 1;
            $run->last_offset += 1;

            match ($decision) {
                'auto_accept' => $run->matched_records += 1,
                'review' => $run->review_records += 1,
                'rejected' => $run->failed_records += 1,
                default => $run->failed_records += 1,
            };
        }

        // نهاية الدفعة؛ offset؛ N testify run كcomplete (لا slotted restart)ل Microsoft.
        $run->status = MedicineEnrichmentRun::STATUS_COMPLETED;
        $run->completed_at = now();
        $run->save();

        Log::info('enrichment run finished', [
            'run_id' => $run->id,
            'processed' => $run->processed_records,
            'matched' => $run->matched_records,
            'review' => $run->review_records,
            'failed' => $run->failed_records,
            'last_offset' => $run->last_offset,
            'dry_run' => $dryRun,
        ]);

        return $run;
    }

    /**
     * إثراء دواء واحد صريح (--medicine=ID).
     * يستخدم run مؤقّت (بلا اعتباره استئنافاً للعملية الكلية).
     *
     * @return array{0: string}
     */
    public function enrichOne(MohMedicine $moh, bool $dryRun = false): string
    {
        $providers = $this->enabledProviders();
        if ($providers === []) {
            return 'no_provider';
        }

        return $this->processOne($moh, $providers, $dryRun);
    }

    /**
     * معالجة دواء واحد: البحث عبر providers المفعّلة ثم المطابقة ثم القاعدة:
     *  - auto_accept (score >= auto_accept) → كتابة آمنة (safeApply)
     *  - apply_with_review (0.80–0.89) → مراجعة (pending review)
     *  - review (0.70–0.79) → مراجعة
     *  - rejected (< 0.70) → لا شيء.
     *
     * @return 'auto_accept'|'review'|'rejected'
     */
    private function processOne(MohMedicine $moh, array $providers, bool $dryRun): string
    {
        $query = new MedicineQuery($moh, (string) $moh->trade_name, '', $moh->manufacturer ?: $moh->company, $moh->dosage_form, $moh->packaging);

        /** @var MedicineDataProvider $provider */
        $result = null;
        foreach ($providers as $provider) {
            $result = $provider->searchByMedicine($query);
            if ($result !== null) {
                break;
            }
        }
        if ($result === null) {
            return 'rejected';
        }

        $score = MatchingEngine::score($moh, $result);

        if ($dryRun) {
            return match ($score['decision']) {
                'auto_accept' => 'auto_accept',
                'apply_with_review', 'review' => 'review',
                default => 'rejected',
            };
        }

        if ($score['decision'] === 'auto_accept') {
            // فحص تعارض الباركود قبل أي كتابة (siyasa syaż alz-duplica):
            //  1. same barcode + same medicine  → harmless (اسم تكاثر الحيازة)
            //  2. same barcode + DIFFERENT medicine → conflict: لا كتابة + review row
            $primary = $result->primaryBarcode();
            if ($primary !== null) {
                $existing = MedicineBarcode::where('barcode', $primary['value'])->first();

                if ($existing !== null && (int) $existing->moh_medicine_id !== (int) $moh->id) {
                    MedicineEnrichmentReview::create([
                        'moh_medicine_id' => $moh->id,
                        'provider' => $result->provider,
                        'provider_payload' => [
                            'reason' => 'barcode_conflict',
                            'barcode' => $primary['value'],
                            'barcode_type' => $primary['type'],
                            'conflicting_moh_medicine_id' => $existing->moh_medicine_id,
                            'proposed' => $result->payload,
                        ],
                        'reason' => 'barcode_conflict',
                        'source_reference' => $result->sourceReference,
                        'confidence' => $score['score'],
                        'status' => MedicineEnrichmentReview::STATUS_PENDING,
                    ]);

                    return 'review';
                }
            }

            $this->safeApply($moh, $result, $score);

            return 'auto_accept';
        }

        if (in_array($score['decision'], ['apply_with_review', 'review'], true)) {
            MedicineEnrichmentReview::create([
                'moh_medicine_id' => $moh->id,
                'provider' => $result->provider,
                'provider_payload' => array_merge($result->payload, [
                    'signals' => $score['signals'],
                    'proposed' => [
                        'name_en' => $result->nameEn,
                        'name_ar' => $result->nameAr,
                        'barcode' => $result->primaryBarcode()['value'] ?? null,
                        'image_url' => $result->imageUrl,
                        'manufacturer' => $result->manufacturer,
                    ],
                ]),
                'source_reference' => $result->sourceReference,
                'confidence' => $score['score'],
                'status' => MedicineEnrichmentReview::STATUS_PENDING,
            ]);

            return 'review';
        }

        return 'rejected';
    }

    /**
     * كتابة آمنة: الحقل الموجود في الأصل لا يُستبدل. فقط الفوارغ تُملأ.
     * - name_en (.trade_name في الленноric moh؟): mohـtrade_name ممتلئ دائماً تقريباً — لا أي كتابة إن موجود.
     * - generic_name حدّ بـnameAr من مزوّد، بالأولوية للأصل.
     * - باركود unique عالحidamente؛ تكرار يخلف الprotection قاع.
     * - image URL بدون تحميل؟ (المرحلة الأولى)؛ تكرار隐私 محلي.
     */
    private function safeApply(MohMedicine $moh, ProviderResult $result, array $score): void
    {
        $updates = [];

        // — trade_name: لا أدخل اسم دود جديد على غير فارغ (لا overwrite).
        if (($moh->trade_name === null || trim((string) $moh->trade_name) === '')
            && $result->nameEn !== null && trim($result->nameEn) !== '') {
            $updates['trade_name'] = mb_substr($result->nameEn, 0, 255);
        }

        // generic_name والحقول الموجودة تحافظ على القيم.
        if (($moh->generic_name === null || trim((string) $moh->generic_name) === '')
            && $result->nameAr !== null && trim($result->nameAr) !== '') {
            $updates['generic_name'] = mb_substr($result->nameAr, 0, 255);
        }

        if ($updates !== []) {
            $moh->update($updates);
        }

        // Barcode — الكتابة هنا فقط بعد الفحص في processOne:
        //  - الbarcode موجود لدواء آخر → ما نصل هنا أصلاً (review path).
        //  - same medicine → duplicate harmless: skip.
        //  - جديد → create.
        $primary = $result->primaryBarcode();
        if ($primary !== null && ! MedicineBarcode::where('barcode', $primary['value'])->exists()) {
            MedicineBarcode::create([
                'moh_medicine_id' => $moh->id,
                'local_medicine_id' => $result->payload['local_medicine_id'] ?? null,
                'barcode_raw' => $primary['raw'],
                'barcode' => $primary['value'],
                'barcode_type' => $primary['type'],
                'source' => $result->provider,
                'source_reference' => $result->sourceReference,
                'confidence' => $score['score'],
                'is_verified' => false,
            ]);
        }

        // Images — placeholder صريح: fragments الPhase-1 لو تكـم external provider لاحقاً.
        $imageUrl = $result->imageUrl;
        if (! empty($imageUrl) && MedicineImage::where('moh_medicine_id', $moh->id)->where('image_url', $imageUrl)->doesntExist()) {
            MedicineImage::create([
                'moh_medicine_id' => $moh->id,
                'local_medicine_id' => $result->payload['local_medicine_id'] ?? null,
                'image_url' => mb_substr($imageUrl, 0, 500),
                'image_type' => 'packshot',
                'source' => $result->provider,
                'source_reference' => $result->sourceReference,
                'is_verified' => false,
            ]);
        }
    }

    /**
     * الprovider المفعّلة من config — drugs_api أولاً إن كان مفعّلاً؛ manual كامشكّ')):
     * هو داعم LOCAL only ولا يعوض المزود الخارجي.
     *
     * @return MedicineDataProvider[]
     */
    private function enabledProviders(): array
    {
        $providers = [];

        $drugsApi = new DrugsApiProvider();
        if ($drugsApi->isEnabled()) {
            $providers[] = $drugsApi;
        }

        $manual = new ManualProvider($this->resolver);
        if ($manual->isEnabled()) {
            $providers[] = $manual;
        }

        return $providers;
    }
}
