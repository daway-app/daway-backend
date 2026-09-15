<?php

namespace App\Services\Enrichment\Providers;

use App\Models\MohMedicine;

/** غلف مووّد كائن موحّد يصل الproviders المضاف مع بعض، بلا افتراض inner types. */
final class MedicineQuery
{
    public function __construct(
        public readonly MohMedicine $medicine,
        public readonly string $nameEn = '',
        public readonly string $nameAr = '',
        public readonly ?string $manufacturer = null,
        public readonly ?string $dosageForm = null,
        public readonly ?string $packSize = null,
    ) {}
}
