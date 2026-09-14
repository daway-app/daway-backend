<?php

namespace App\Exports;

use App\Models\InventoryImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * تقرير أخطاء الاستيراد — نفس بنية القالب + أعمدة التشخيص.
 *
 * الهدف: يعدّل الصيدلي ملفه الأصلي ثم يعيد رفعه، فلا نغيّر أسماء الأعمدة
 * الأساسية ولا ترتيبها. أعمدة التشخيص (الحالة/الأخطاء/التحذيرات/الاقتراحات)
 * تُضاف في النهاية فقط.
 *
 * حماية حقن الصيغ: أي خلية نصية تبدأ بـ = + - @ تُسبق بفاصلة عليا، مطابقةً
 * لسلوك LogsExport::safeCell — لأن المحتوى أصله من ملف رفعه المستخدم.
 */
class InventoryErrorsExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** الأعمدة الأساسية — نفس ترتيب القالب. */
    private const INPUT_COLUMNS = [
        'trade_name',
        'trade_name_ar',
        'active_ingredient',
        'price',
        'quantity',
        'barcode',
        'min_stock',
        'is_available',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  صفوف المعاينة من rows_payload
     */
    public function __construct(private readonly array $rows)
    {
    }

    public static function fromImport(InventoryImport $import): self
    {
        return new self($import->rows());
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $out = [];
        $separator = (string) __('pharmacy_import.export_list_separator');

        foreach ($this->rows as $row) {
            $input = (array) ($row['input'] ?? []);

            $line = [(int) ($row['row'] ?? 0)];

            foreach (self::INPUT_COLUMNS as $column) {
                $value = $input[$column] ?? null;

                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }

                $line[] = $this->safeCell($value);
            }

            // أعمدة التشخيص
            $line[] = $this->safeCell($this->statusLabel((string) ($row['status'] ?? '')));

            $line[] = $this->safeCell($this->labelList(
                (array) ($row['errors'] ?? []),
                'pharmacy_import.err_',
                $separator
            ));

            $line[] = $this->safeCell($this->labelList(
                (array) ($row['warnings'] ?? []),
                'pharmacy_import.warn_',
                $separator
            ));

            $line[] = $this->safeCell($this->suggestionList((array) ($row['suggestions'] ?? []), $separator));

            $out[] = $line;
        }

        return $out;
    }

    public function headings(): array
    {
        return array_merge(
            [__('pharmacy_import.row_number')],
            [
                'trade_name',
                'trade_name_ar',
                'active_ingredient',
                'price',
                'quantity',
                'barcode',
                'min_stock',
                'is_available',
            ],
            [
                __('pharmacy_import.export_status'),
                __('pharmacy_import.export_errors'),
                __('pharmacy_import.export_warnings'),
                __('pharmacy_import.export_suggestions'),
            ]
        );
    }

    public function title(): string
    {
        return __('pharmacy_import.export_errors_title');
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2D9D9']],
            ],
        ];
    }

    /** حالة الصف → نص مترجم، مع تراجع آمن لأي حالة غير معروفة. */
    private function statusLabel(string $status): string
    {
        if ($status === '') {
            return '';
        }

        $key = 'pharmacy_import.status_'.$status;

        return __($key) === $key ? $status : __($key);
    }

    /**
     * ترجمة قائمة رموز إلى نص واحد مفصول.
     *
     * @param  array<int, mixed>  $codes
     */
    private function labelList(array $codes, string $prefix, string $separator): string
    {
        $labels = [];

        foreach ($codes as $code) {
            $code = (string) $code;

            if ($code === '') {
                continue;
            }

            $key = $prefix.$code;
            $labels[] = __($key) === $key ? $code : __($key);
        }

        return implode($separator, array_unique($labels));
    }

    /**
     * الاقتراحات: الاسم + مصدره، بلا معرّفات تقنية لا تفيد الصيدلي.
     *
     * @param  array<int, array<string, mixed>>  $suggestions
     */
    private function suggestionList(array $suggestions, string $separator): string
    {
        $labels = [];

        foreach ($suggestions as $suggestion) {
            $name = trim((string) ($suggestion['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $source = ($suggestion['source'] ?? '') === 'moh'
                ? __('pharmacy_import.status_MOH_MATCH')
                : __('pharmacy_import.export_matched');

            $labels[] = $name.' ('.$source.')';
        }

        return implode($separator, array_unique($labels));
    }

    /**
     * حماية من حقن الصيغ في ملفات CSV/Excel.
     * مطابق لسلوك LogsExport::safeCell — أي قيمة تبدأ بـ = + - @ تُسبق بـ '.
     */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
