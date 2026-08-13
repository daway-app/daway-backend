<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

class LogsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $logs;

    public function __construct(Collection $logs)
    {
        $this->logs = $logs;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->logs;
    }

    /**
     * @param Activity $log
     * @return array
     */
    public function map($log): array
    {
        return [
            $log->id,
            $log->causer->name ?? 'System',
            $log->description,
            $log->subject ? class_basename($log->subject_type) . ' #' . $log->subject_id : '',
            $log->properties->get('ip') ?? 'N/A',
            $log->created_at->toDateTimeString(),
        ];
    }

    /**
     * @return array
     */
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

    /**
     * @return string
     */
    public function title(): string
    {
        return 'Audit Logs Report';
    }

    /**
     * @param Worksheet $sheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row (header)
            1    => ['font' => ['bold' => true, 'size' => 12], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFA0A0A0']]],
        ];
    }
}
