<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

class LogsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $logs;

    public function __construct(Collection $logs)
    {
        $this->logs = $logs;
    }

    /**
     * @return Collection
     */
    public function collection()
    {
        return $this->logs;
    }

    /**
     * @param  Activity  $log
     */
    public function map($log): array
    {
        return [
            $log->id,
            $this->safeCell($log->causer->name ?? 'System'),
            $this->safeCell($log->description),
            $this->safeCell($log->subject ? class_basename($log->subject_type).' #'.$log->subject_id : ''),
            $this->safeCell($log->properties->get('ip') ?? 'N/A'),
            $log->created_at->toDateTimeString(),
        ];
    }

    /**
     * Protect against CSV/formula injection: prefix cells starting with
     * =, +, - or @ with a single quote so they are treated as text.
     */
    private function safeCell($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    public function headings(): array
    {
        return [
            'ID',
            'User',
            'Event',
            'Subject',
            'IP Address',
            'Date & Time',
        ];
    }

    public function title(): string
    {
        return 'Audit Logs Report';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row (header)
            1 => ['font' => ['bold' => true, 'size' => 12], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFA0A0A0']]],
        ];
    }
}
