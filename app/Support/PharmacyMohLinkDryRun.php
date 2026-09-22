<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * خدمة إحصائية لعمل Dry Run على ربط pharmacy_medicines بـ moh_medicines.
 *
 * تقرأ نفس المنطق الخاص بـ BackfillPharmacyMohLinks --dry-run
 * دون أي كتابة — تُستَخدم من Admin endpoint المؤقت للتحقق من الحالة
 * في بيئة Production دون الحاجة لتشغيل Artisan command مباشرة.
 *
 * صُمِّمت لتكون Read-Only تماماً: لا تحتوي على أي UPDATE/INSERT/DELETE/SAVE/UPSERT.
 */
class PharmacyMohLinkDryRun
{
    /**
     * تشغيل التحليل الإحصائي بدون أي تعديل بيانات.
     *
     * @return array{
     *     total: int,
     *     linked: int,
     *     null: int,
     *     matched: int,
     *     ambiguous: int,
     *     unmatched: int,
     *     ambiguous_samples: list<array>,
     *     unmatched_samples: list<array>
     * }
     */
    public function run(): array
    {
        $hasMohMedicineIdColumn = Schema::hasColumn('pharmacy_medicines', 'moh_medicine_id');

        $totalRows = DB::table('pharmacy_medicines')->count();

        if ($hasMohMedicineIdColumn) {
            $alreadyLinkedCount = DB::table('pharmacy_medicines')
                ->whereNotNull('moh_medicine_id')
                ->count();
            $nullCount = DB::table('pharmacy_medicines')
                ->whereNull('moh_medicine_id')
                ->count();
        } else {
            $alreadyLinkedCount = 0;
            $nullCount = $totalRows;
        }

        $matched = 0;
        $ambiguous = 0;
        $unmatched = 0;

        $ambiguousSamples = [];
        $unmatchedSamples = [];

        // نفس المنطق: نعالج فقط الصفوف التي لديها NULL moh_medicine_id
        // أو كل الصفوف إذا لم يكن العمود موجوداً (pre-migration — للتدقيق فقط).
        $rowsQuery = DB::table('pharmacy_medicines')->select(['id', 'medicine_id']);
        if ($hasMohMedicineIdColumn) {
            $rowsQuery->whereNull('moh_medicine_id');
        }
        $rows = $rowsQuery->get();

        foreach ($rows as $row) {
            $medicine = \App\Models\Medicine::find($row->medicine_id);

            if (! $medicine) {
                $unmatched++;
                if (count($unmatchedSamples) < 5) {
                    $unmatchedSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => 'not found',
                    ];
                }
                continue;
            }

            $matches = \App\Models\MohMedicine::where('trade_name', $medicine->trade_name)->count();

            if ($matches === 1) {
                $matched++;
            } elseif ($matches > 1) {
                $ambiguous++;
                if (count($ambiguousSamples) < 5) {
                    $matchingMohs = \App\Models\MohMedicine::where('trade_name', $medicine->trade_name)
                        ->select('id', 'trade_name', 'manufacturer', 'moh_product_id')
                        ->get()
                        ->map(fn ($m) => [
                            'id' => $m->id,
                            'trade_name' => $m->trade_name,
                            'manufacturer' => $m->manufacturer,
                        ])->values()->all();
                    $ambiguousSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => $medicine->trade_name,
                        'matching_moh_count' => $matches,
                        'matching_mohs' => $matchingMohs,
                    ];
                }
            } else {
                $unmatched++;
                if (count($unmatchedSamples) < 5) {
                    $unmatchedSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => $medicine->trade_name,
                    ];
                }
            }
        }

        return [
            'total' => $totalRows,
            'linked' => $hasMohMedicineIdColumn ? $alreadyLinkedCount : 0,
            'null' => $hasMohMedicineIdColumn ? $nullCount : $totalRows,
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched' => $unmatched,
            'ambiguous_samples' => $ambiguousSamples,
            'unmatched_samples' => $unmatchedSamples,
        ];
    }
}
