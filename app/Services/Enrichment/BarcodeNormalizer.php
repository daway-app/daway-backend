<?php

namespace App\Services\Enrichment;

/**
 * تطهيب (Normalize) آمن للباركود — بلا تغيير الرقم الحقيقي:
 * تدعم EAN-13/EAN-8/UPC-A/GTIN-14. يخلو من checksum الـGTIN-14 (المضامن
 * في حقل wide/GTIN-14 غير مطمئن — لا نحدد أكثر مما يمكن إثباته).
 */
final class BarcodeNormalizer
{
    public const TYPE_EAN13 = 'EAN13';

    public const TYPE_EAN8 = 'EAN8';

    public const TYPE_UPCA = 'UPCA';

    public const TYPE_GTIN14 = 'GTIN14';

    /**
     * تنقية شكلية (أرقام فقط) دون تحويل قيمة؛ ترجع null إن غير قابل للنمنمة.
     *
     * @return array{barcode: string, type: string, raw: string}|null
     */
    public static function normalize(?string $value): ?array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // إزالة فواصل/مسافات/شرطات الزائفة — حفظ الأصلي إن اختلفت.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $digits = ltrim($digits, '0') === '' ? '' : $digits;

        if ($digits === '') {
            return null;
        }

        $type = self::detectType($digits);
        if ($type === null) {
            return null;
        }

        return [
            'barcode' => $digits,
            'type' => $type,
            'raw' => $raw === $digits ? '' : $raw,
        ];
    }

    /** checksum صحيح فقط للأناضول EAN8/EAN13/UPC-A — حالة اختيارية. */
    public static function isChecksumValid(string $digits, string $type): ?bool
    {
        if ($type === self::TYPE_GTIN14) {
            return null; // نتجنب حكم checksum لـGTIN-14 (checksum namespace خارجي)
        }

        $digits = strrev($digits);            // from the right
        $frameFactor = 3;
        $sum = 0;

        foreach (str_split(substr($digits, 0, -1)) as $digit) {
            $sum += ((int) $digit) * $frameFactor;
            $frameFactor = 4 - $frameFactor;   // 3,1,3,1,...
        }

        $expected = (10 - ($sum % 10)) % 10;

        return ((int) substr($digits, -1)) === $expected;
    }

    /** نوع السقم فقط (حسب الأنواع المطلوبة) — null غير معروف. */
    public static function detectType(string $digits): ?string
    {
        return match (strlen($digits)) {
            8 => self::TYPE_EAN8,
            12 => self::TYPE_UPCA,
            13 => self::TYPE_EAN13,
            14 => self::TYPE_GTIN14,
            default => null,
        };
    }

    /** فحص checksum للـEAN13 (اختياري — من verified sources فقط). */
    public static function isValidEan13(string $digits): bool
    {
        if (strlen($digits) !== 13) {
            return false;
        }

        $checksum = (int) substr($digits, -1);
        $digits = substr($digits, 0, -1);
        $digits = strrev($digits);
        $frameFactor = 3;
        $sum = 0;
        foreach (str_split($digits) as $digit) {
            $sum += ((int) $digit) * $frameFactor;
            $frameFactor = 4 - $frameFactor; // 3,1,3,1...
        }

        return ((10 - ($sum % 10)) % 10) === $checksum;
    }
}
