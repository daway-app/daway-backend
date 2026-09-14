<?php

namespace App\Services\InventoryImport;

use App\Imports\InventoryRowsImport;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * قارئ ومدقّق ملف الاستيراد.
 *
 * المبدأ: كل ما يدخل من ملف غير موثوق. هنا نتعامل مع BOM، المسافات،
 * الأعمدة الناقصة/الزيادة، الأرقام غير الصالحة، الأرقام العربية-الهندية،
 * والقيم المنطقية الغريبة — قبل أن يلمس أي صف قاعدة البيانات.
 *
 * ملاحظة تصميمية (قاعدة عمل معلنة):
 *  - quantity إلزامية وصحيحة — هي جوهر الاستيراد، ولا بديل لها.
 *  - price فارغة تُقبل كصفر مع تحذير ظاهر (سعر غير معروف حالة واقعية،
 *    والصفر قيمة صالحة في المخطط) — لا تُسقط الصف.
 *  - أي قيمة سالبة أو غير رقمية في الاثنين = خطأ يُسقط الصف.
 */
final class InventoryFileParser
{
    /** الأعمدة الإلزامية (بعد التطبيع). */
    private const REQUIRED_COLUMNS = ['trade_name', 'price', 'quantity'];

    /** الأعمدة المعروفة — ما عداها يُهمل بتحذير. */
    private const KNOWN_COLUMNS = [
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
     * @return array{rows: array<int, array<string, mixed>>, unknown_columns: array<int, string>}
     *
     * @throws ValidationException
     */
    public function parse(string $absolutePath): array
    {
        $raw = $this->read($absolutePath);

        if ($raw === []) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_empty_file'),
            ]);
        }

        $headerIndex = $this->findHeaderRow($raw);

        if ($headerIndex === null) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_missing_header'),
            ]);
        }

        [$map, $unknownColumns] = $this->mapColumns($raw[$headerIndex]);

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($map)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_missing_columns', ['columns' => implode(', ', $missing)]),
            ]);
        }

        $maxRows = (int) config('inventory_import.max_rows', 5000);
        $rows = [];
        $rowNumber = 0;

        foreach (array_slice($raw, $headerIndex + 1) as $offset => $cells) {
            // رقم السطر كما يراه المستخدم في Excel (1-based) — الرأس سطر 1
            $excelRow = $headerIndex + $offset + 2;

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $rowNumber++;

            if ($rowNumber > $maxRows) {
                throw ValidationException::withMessages([
                    'file' => __('pharmacy_import.error_too_many_rows', ['max' => number_format($maxRows)]),
                ]);
            }

            $rows[] = $this->buildRow($cells, $map, $excelRow);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_no_data_rows'),
            ]);
        }

        return ['rows' => $rows, 'unknown_columns' => $unknownColumns];
    }

    /**
     * @return array<int, array<int, mixed>>
     *
     * @throws ValidationException
     */
    private function read(string $absolutePath): array
    {
        $previousDelimiter = config('excel.imports.csv.delimiter');

        try {
            $this->configureCsvDelimiter($absolutePath);

            $sheets = Excel::toArray(new InventoryRowsImport, $absolutePath);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'file' => __('pharmacy_import.error_unreadable_file'),
            ]);
        } finally {
            // لا نترك إعداداً عاماً معدَّلاً بعد القراءة
            config(['excel.imports.csv.delimiter' => $previousDelimiter]);
        }

        $first = $sheets[0] ?? [];

        if (! is_array($first)) {
            return [];
        }

        // BOM قد يلتصق بأول خلية في ملفات CSV المُصدَّرة من أنظمة أخرى
        if (isset($first[0][0]) && is_string($first[0][0])) {
            $first[0][0] = preg_replace('/^\x{FEFF}|\x{EF}\x{BB}\x{BF}/u', '', $first[0][0]) ?? $first[0][0];
        }

        return $first;
    }

    /**
     * كشف فاصل أعمدة الـ CSV وفرضه صراحةً على القارئ.
     *
     * السبب: قارئ CSV يخمّن الفاصل من محتوى أول سطر. ملف يبدأ بسطر عنوان
     * بلا فواصل (مثل «تقرير مخزون») يجعله يختار فاصلاً خاطئاً — فتصير خلية
     * العناوين نصاً واحداً `trade_name,price,quantity` وينهار التحليل كاملاً
     * برسالة «لم نتعرّف على صف العناوين» وهي مضلِّلة.
     *
     * الكشف يجري على البايتات الخام: الفواصل كلها ASCII، فلا يتأثر بترميز
     * الملف (UTF-8 أو Windows-1256).
     */
    private function configureCsvDelimiter(string $absolutePath): void
    {
        if (! in_array(strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            return;
        }

        $delimiter = $this->detectDelimiter($absolutePath);

        if ($delimiter !== null) {
            config(['excel.imports.csv.delimiter' => $delimiter]);
        }
    }

    /**
     * اختيار الفاصل الأرجح: الفاصل الصحيح يظهر بالعدد نفسه في معظم الأسطر،
     * بخلاف فاصل يرد داخل قيمة نصية في سطر أو سطرين.
     *
     * المسافة ليست مرشّحاً — ليست فاصلاً صالحاً في CSV، واعتبارها كذلك هو
     * تحديداً ما يفسد ملفات فيها سطر عنوان.
     */
    private function detectDelimiter(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        $lines = [];

        try {
            while (count($lines) < 20 && ($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($lines === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = [];

            foreach ($lines as $line) {
                $occurrences = substr_count($line, $candidate);

                if ($occurrences > 0) {
                    $counts[] = $occurrences;
                }
            }

            if ($counts === []) {
                continue;
            }

            $frequency = array_count_values($counts);
            arsort($frequency);

            $mostCommonCount = (int) array_key_first($frequency);
            $linesWithThatCount = (int) $frequency[$mostCommonCount];

            // الاتساق أولاً، ثم عدد الأعمدة
            $score = $linesWithThatCount * 100 + $mostCommonCount;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * أول صف يحتوي خليتين معروفيتين على الأقل يُعتبر رأس الجدول.
     * يسمح بوجود أسطر تمهيدية فوق الجدول.
     *
     * @param  array<int, array<int, mixed>>  $raw
     */
    private function findHeaderRow(array $raw): ?int
    {
        foreach ($raw as $index => $cells) {
            if (! is_array($cells)) {
                continue;
            }

            $known = 0;

            foreach ($cells as $cell) {
                if (in_array($this->normalizeHeader($cell), self::KNOWN_COLUMNS, true)) {
                    $known++;
                }
            }

            if ($known >= 2) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headerCells
     * @return array{0: array<string, int>, 1: array<int, string>}
     *
     * @throws ValidationException
     */
    private function mapColumns(array $headerCells): array
    {
        $map = [];
        $unknown = [];

        foreach ($headerCells as $position => $cell) {
            $name = $this->normalizeHeader($cell);

            if ($name === '') {
                continue;
            }

            if (! in_array($name, self::KNOWN_COLUMNS, true)) {
                $unknown[] = $name;

                continue;
            }

            // أول ظهور يفوز — عمود مكرر لا يستبدل الأول بصمت
            $map[$name] ??= (int) $position;
        }

        return [$map, array_values(array_unique($unknown))];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    private function buildRow(array $cells, array $map, int $excelRow): array
    {
        $errors = [];
        $warnings = [];

        $tradeName = $this->string($cells[$map['trade_name']] ?? null);
        $tradeNameAr = $this->string($cells[$map['trade_name_ar'] ?? -1] ?? null);
        $activeIngredient = $this->string($cells[$map['active_ingredient'] ?? -1] ?? null);
        $barcode = $this->string($cells[$map['barcode'] ?? -1] ?? null);

        $priceRaw = $cells[$map['price']] ?? null;
        $quantityRaw = $cells[$map['quantity']] ?? null;
        $minStockRaw = $cells[$map['min_stock'] ?? -1] ?? null;
        $availableRaw = $cells[$map['is_available'] ?? -1] ?? null;

        // --- الاسم: لا بد من مفتاح مطابقة واحد على الأقل ---
        if ($tradeName === '' && $tradeNameAr === '') {
            $errors[] = 'missing_name';
        }

        // --- السعر ---
        $price = null;

        if ($this->isBlank($priceRaw)) {
            $price = 0.0;
            $warnings[] = 'missing_price';
        } else {
            $parsed = $this->number($priceRaw);

            if ($parsed === null || $parsed < 0) {
                $errors[] = 'invalid_price';
            } else {
                $price = round($parsed, 2);
            }
        }

        // --- الكمية ---
        $quantity = null;

        if ($this->isBlank($quantityRaw)) {
            $errors[] = 'invalid_quantity';
        } else {
            $parsed = $this->number($quantityRaw);

            // نمنع الكسور والطفح: الكمية عدد صحيح غير سالب
            if ($parsed === null || $parsed < 0 || $parsed > 4294967295 || floor($parsed) !== $parsed) {
                $errors[] = 'invalid_quantity';
            } else {
                $quantity = (int) $parsed;
            }
        }

        // --- حد المخزون المنخفض (اختياري) ---
        $minStock = null;

        if (! $this->isBlank($minStockRaw)) {
            $parsed = $this->number($minStockRaw);

            if ($parsed === null || $parsed < 0 || $parsed > 4294967295 || floor($parsed) !== $parsed) {
                $errors[] = 'invalid_min_stock';
            } else {
                $minStock = (int) $parsed;
            }
        }

        // --- التوفّر (اختياري) — افتراضياً: متوفر إذا الكمية > 0 ---
        $isAvailable = null;

        if (! $this->isBlank($availableRaw)) {
            $isAvailable = $this->boolean($availableRaw);

            if ($isAvailable === null) {
                $errors[] = 'invalid_is_available';
            }
        }

        return [
            'row' => $excelRow,
            'input' => [
                'trade_name' => $tradeName,
                'trade_name_ar' => $tradeNameAr !== '' ? $tradeNameAr : null,
                'active_ingredient' => $activeIngredient !== '' ? $activeIngredient : null,
                'price' => $price,
                'quantity' => $quantity,
                'barcode' => $barcode !== '' ? $barcode : null,
                'min_stock' => $minStock,
                'is_available' => $isAvailable ?? ($quantity !== null && $quantity > 0),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param array<int, mixed> $cells */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (! $this->isBlank($cell)) {
                return false;
            }
        }

        return true;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /** نص نظيف: تحويل آمن + إزالة مسافات زائدة + توحيد المسافات الداخلية. */
    private function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value;
    }

    /**
     * قراءة رقم بأمان: أرقام عربية-هندية، فواصل، فراغات، صيغة علمية.
     * يعيد null لأي قيمة غير رقمية أو غير منتهية (NaN/INF).
     */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        // أرقام عربية-هندية (٠١٢…) وفارسية (۰۱۲…) → ASCII
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => '',
        ]);

        // فواصل الآلاف والمسافات — لكن نحافظ على العلامة العشرية
        $text = str_replace([',', ' ', "\u{00A0}"], '', $text);

        if (! is_numeric($text)) {
            return null;
        }

        $number = (float) $text;

        return is_finite($number) ? $number : null;
    }

    /** قيمة منطقية مرنة: 1/0، true/false، نعم/لا، yes/no. غير ذلك null. */
    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        $text = mb_strtolower($this->string($value));

        return match ($text) {
            '1', 'true', 'yes', 'y', 'on', 'نعم', 'متوفر', 'صح' => true,
            '0', 'false', 'no', 'n', 'off', 'لا', 'غير متوفر', 'خطأ' => false,
            default => null,
        };
    }

    /** تطبيع اسم عمود الرأس: BOM، حالة الأحرف، المسافات، الشرطات. */
    private function normalizeHeader(mixed $cell): string
    {
        $name = $this->string($cell);

        if ($name === '') {
            return '';
        }

        $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
        $name = mb_strtolower($name);
        $name = str_replace([' ', '-', '.'], '_', $name);

        return trim($name, '_');
    }
}
