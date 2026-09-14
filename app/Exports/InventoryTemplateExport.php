<?php

namespace App\Exports;

use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * قالب ملف الاستيراد — بوضعين:
 *   - empty:   صف عناوين فقط، ليكتب الصيدلي بياناته من الصفر.
 *   - current: مخزون الصيدلية الحالي بنفس ترتيب الأعمدة، ليعدّله ويعيد رفعه.
 *
 * الأعمدة هنا هي "العقد" مع InventoryFileParser — أي تغيير في الترتيب أو
 * الأسماء يجب أن يقابله تحديث في KNOWN_COLUMNS هناك.
 *
 * ملاحظة مقصودة: لا نضع صف مثال داخل الملف. صف مثال يُنسى فيُستورد فعلاً،
 * ونداء الأخطاء أغلى من ربح توضيحي بسيط — الشرح معروض في الواجهة.
 */
class InventoryTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public const MODE_EMPTY = 'empty';

    public const MODE_CURRENT = 'current';

    /** ترتيب الأعمدة الملزم — مصدر واحد للحقيقة.
     *  barcode/min_stock أُزيل بناءً على قرار الأدمن: بلا ارتباط فعلي
     *  (الباركود غير مخزّن، وmin_stock لا يغيّر حد التنبيه الذي
     *  يعتمد LOW_STOCK_THRESHOLD) — لا مكان لهما في الاستيراد.
     */
    public const COLUMNS = [
        'trade_name',
        'trade_name_ar',
        'active_ingredient',
        'price',
        'quantity',
        'is_available',
    ];

    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    private string $mode;

    public function __construct(string $mode = self::MODE_EMPTY)
    {
        $this->mode = $mode === self::MODE_CURRENT ? self::MODE_CURRENT : self::MODE_EMPTY;
    }

    /**
     * بناء الوضع "الحالي" من مخزون صيدلية — استعلام واحد مع eager load،
     * بلا استعلام لكل سطر (لا N+1).
     */
    public static function forPharmacy(Pharmacy $pharmacy): self
    {
        $export = new self(self::MODE_CURRENT);

        $items = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine:id,trade_name,trade_name_ar,active_ingredient')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $medicine = $item->medicine;

            // دواء محذوف من الكتالوج (نظرياً) — نتخطاه بدل كتابة صف بلا اسم
            if ($medicine === null) {
                continue;
            }

            $rows[] = [
                (string) $medicine->trade_name,
                (string) ($medicine->trade_name_ar ?? ''),
                (string) ($medicine->active_ingredient ?? ''),
                (float) $item->price,
                (int) $item->quantity,
                $item->is_available ? 1 : 0,
            ];
        }

        $export->rows = $rows;

        return $export;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return self::COLUMNS;
    }

    public function title(): string
    {
        return $this->mode === self::MODE_CURRENT
            ? __('pharmacy_import.export_current_title')
            : __('pharmacy_import.export_template_title');
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9EDF2']],
            ],
        ];
    }
}
