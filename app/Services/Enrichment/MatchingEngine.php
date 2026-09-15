<?php

namespace App\Services\Enrichment;

use App\Models\MohMedicine;
use App\Services\Enrichment\Providers\ProviderResult;

/**
 * محرك مطابقة متعدد الإشارات.
 * الأوزان والحدود من config('enrichment') — ليست معطلة في الكود.
 *
 * scoring: لا نُجمع الإشارات عمياء — أعلى إشارة هي التي تشتري القرار،
 * والإشارات المتبقية (واحدة على الأقل) تتطلب مستوى معيناً من الثقة.
 * سبب: طبقة الإشارات المُجمّعة بجمع حد apparentياً تعطي 0.85-0.95 بشكل مخادع لعناصر ضعيفة متعددة،
 * بينما الإشارة القوية الفعلية (باركود مطابق / مطابقة بالاسم المعجم) توجب قرارًا سليمًا.
 */
final class MatchingEngine
{
    /**
     * @return array{score: float, signals: array<string, float>, decision: string}
     */
    public static function score(MohMedicine $medicine, ProviderResult $candidate): array
    {
        $signals = [];

        // 1) الباركود فارغ كله عند الprovider يعني إشارة قوية فورية (نفس المنتج بإمكان ضمّه عبر مطابقة الباركود الفوري بالمفتاح المت(embedded)).
        if ($candidate->hasBarcode()) {
            $signals['barcode_exact'] = (float) (config('enrichment.signals.barcode_exact') ?? 1.0);
        }

        // manufacturer — المطابقة الكاملة بحد عالي (variants من الدواء والنتائج).
        $candidateManufacturer = mb_strtolower(mb_trim((string) ($candidate->manufacturer ?? '')));
        $catalogManufacturer = mb_strtolower(mb_trim((string) ($medicine->manufacturer ?: ($medicine->company ?? ''))));
        if ($candidateManufacturer !== '' && $catalogManufacturer !== '') {
            $sim = NameNormalizer::similarity($candidateManufacturer, $catalogManufacturer);
            if ($sim >= 0.95) {
                $signals['manufacturer'] = (float) (config('enrichment.signals.manufacturer_exact') ?? 0.95);
            } elseif ($sim >= 0.80) {
                $signals['manufacturer_partial'] = 0.60;
            }
        }

        // dosage form — تطابق حرفي
        $doseFormCand = mb_strtolower(mb_trim((string) ($candidate->dosageForm ?? '')));
        $doseFormCat = mb_strtolower(mb_trim((string) $medicine->dosage_form));
        if ($doseFormCand !== '' && $doseFormCat !== '' && ltrim(str_replace(['tablet', 'tablets'], '', $doseFormCand)) === ltrim(str_replace(['tablet', 'tablets'], '', $doseFormCat))) {
            $signals['dosage_form'] = (float) (config('enrichment.signals.dosage_form_exact') ?? 0.70);
        }

        // pack size (moh: packaging)
        $packCand = mb_strtolower(mb_trim((string) ($candidate->packSize ?? '')));
        $packCat = mb_strtolower(mb_trim((string) ($medicine->packaging ?? '')));
        if ($packCand !== '' && $packCat !== '' && $packCand === $packCat) {
            $signals['pack_size'] = (float) (config('enrichment.signals.pack_size_exact') ?? 0.90);
        }

        // name similarity — medical grade: الاسم متطابق تقريباً (الإشارة الأساسية للباركود)
        $nameScore = NameNormalizer::similarity($candidate->nameEn ?? '', $medicine->trade_name);
        if ($nameScore > 0) {
            $signals['name_en'] = $nameScore * (float) (config('enrichment.signals.name_en_similarity') ?? 0.60);
        }

        // active/ingredient match — کو generic في الكاتالوج المعتمد
        $genericScore = NameNormalizer::similarity($candidate->nameEn ?? '', $medicine->generic_name);
        if ($genericScore > 0) {
            $signals['active_ingredient'] = $genericScore * (float) (config('enrichment.signals.active_ingredient_exact') ?? 0.90);
        }

        //        alias — مستوى matching batmapping الخفيف
        $aliasScore = NameNormalizer::similarity($candidate->nameAr ?? '', ($medicine->generic_name ?? ''));
        if ($aliasScore >= 0.95) {
            $signals['alias'] = (float) (config('enrichment.signals.alias_match') ?? 0.55);
        }

        $score = $signals === [] ? 0.0 : max($signals);

        $autoAccept = (float) (config('enrichment.thresholds.auto_accept') ?? 0.90);
        $reviewFloor = (float) (config('enrichment.thresholds.review') ?? 0.70);

        $decision = match (true) {
            $score >= $autoAccept => 'auto_accept',
            $score >= 0.80 => 'apply_with_review', // جيد لكن low confidence
            $score >= $reviewFloor => 'review',
            default => 'rejected',
        };

        return ['score' => round(min(1.0, $score), 3), 'signals' => $signals, 'decision' => $decision];
    }
}
