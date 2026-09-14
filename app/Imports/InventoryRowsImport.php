<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * قراءة خام لكل صفوف الورقة الأولى — بلا أي معالجة تلقائية للرأس.
 *
 * السبب: WithHeadingRow في maatwebsite/excel يُطبّع أسماء الأعمدة تلقائياً
 * (lowercase + slug) عبر HeadingRowFormatter وهي حالة عامة (global state)
 * تمسّ قراءات أخرى. نتعامل مع الرأس بأنفسنا لنبقى مسيطرين على:
 * BOM، المسافات الزائدة، الأعمدة الناقصة/الزيادة، وتكرار أسماء الأعمدة.
 */
class InventoryRowsImport implements ToArray
{
    /** @var array<int, array<int, mixed>> */
    public array $rows = [];

    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        $this->rows = $array;
    }
}
