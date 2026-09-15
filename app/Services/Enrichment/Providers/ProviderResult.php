<?php

namespace App\Services\Enrichment\Providers;

/**
 * نتيجة موحّدة من أي Provider — الengine لا يعرف شكل الAPI الداخلي.
 * كل الحقول اختيارية (nullable أو مصفوفة فارغة) — لا يخمّن الProvider.
 */
final class ProviderResult
{
    /**
     * @param  array<int, array{value: string, type: string, raw?: string}>  $barcodes
     * @param  array<string, mixed>  $payload  الرد الخام من المصدر (للمراجعة)
     */
    public function __construct(
        public readonly ?string $nameEn,
        public readonly ?string $nameAr,
        public readonly array $barcodes,
        public readonly ?string $imageUrl,
        public readonly ?string $manufacturer,
        public readonly ?string $strength,
        public readonly ?string $dosageForm,
        public readonly ?string $packSize,
        public readonly string $provider,
        public readonly ?string $sourceReference = null,
        public readonly array $payload = [],
    ) {}

    public static function match(
        ?string $nameEn,
        ?string $nameAr,
        array $barcodes = [],
        ?string $imageUrl = null,
        ?string $manufacturer = null,
        ?string $strength = null,
        ?string $dosageForm = null,
        ?string $packSize = null,
        ?string $sourceReference = null,
        array $payload = [],
        string $provider = 'manual',
    ): self {
        return new self($nameEn, $nameAr, $barcodes, $imageUrl, $manufacturer, $strength, $dosageForm, $packSize, $provider, $sourceReference, $payload);
    }

    public function hasBarcode(): bool
    {
        return $this->barcodes !== [];
    }

    public function primaryBarcode(): ?array
    {
        return $this->barcodes[0] ?? null;
    }
}
