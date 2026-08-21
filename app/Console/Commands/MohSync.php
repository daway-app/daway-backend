<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MohSync extends Command
{
    protected $signature = 'moh:sync {--timeout=180}';

    protected $description = 'مزامنة كتالوج أدوية وزارة الصحة الفلسطينية (المستحضرات المسجلة + قائمة الأسعار)';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $timeout = (int) $this->option('timeout');

        $this->info('جاري جلب المستحضرات المسجلة من وزارة الصحة...');
        $products = $this->fetchJson('https://pharmacy.moh.ps/service/getRegisterProducts', $timeout);
        if ($products === null) {
            $this->error('فشل جلب المستحضرات المسجلة.');
            return self::FAILURE;
        }

        $this->info('جاري جلب قائمة الأسعار...');
        $prices = $this->fetchJson('https://pharmacy.moh.ps/service/getDrugsPublic', $timeout);
        if ($prices === null) {
            $this->error('فشل جلب قائمة الأسعار.');
            return self::FAILURE;
        }

        $productRows = $this->parseProducts($products['aaData'] ?? []);
        $priceRows = $this->parsePrices($prices['aaData'] ?? []);

        $this->info('تم جلب '.count($productRows).' مستحضر و '.count($priceRows).' دواء بالأسعار.');
        $this->info('جاري الدمج والتخزين...');

        $map = [];
        $defaults = [
            'manufacturer' => null,
            'dosage_form' => null,
            'product_class' => null,
            'origin' => null,
            'moh_product_id' => null,
            'generic_name' => null,
            'official_price' => null,
            'packaging' => null,
            'company' => null,
            'availability' => null,
            'moh_drug_id' => null,
            'price_updated_at' => null,
        ];
        foreach ($productRows as $row) {
            $key = $row['_key'];
            unset($row['_key']);
            $map[$key] = array_merge($defaults, $row);
        }
        unset($productRows);

        $merged = 0;
        foreach ($priceRows as $row) {
            $key = $row['_key'];
            unset($row['_key']);
            if (isset($map[$key])) {
                $map[$key] = array_merge($defaults, $map[$key], $row);
                $merged++;
            } else {
                $map[$key] = array_merge($defaults, $row);
            }
        }
        unset($priceRows);

        DB::transaction(function () use ($map) {
            MohMedicine::query()->delete();
            foreach (array_chunk($map, 1000) as $chunk) {
                MohMedicine::insert($chunk);
            }
        });
        unset($map);

        // إبطال الكاش المرتبط بكتالوج الوزارة بعد نجاح المزامنة
        Cache::add('med_catalog_version', 1, 3600 * 24 * 30);
        Cache::increment('med_catalog_version');
        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        $this->info("تم الحفظ بنجاح: ".MohMedicine::count().' دواء ('.$merged.' مدمج مع الأسعار).');

        return self::SUCCESS;
    }

    private function fetchJson(string $url, int $timeout): ?array
    {
        try {
            $request = Http::timeout($timeout)
                ->withHeaders(['Accept' => 'application/json']);

            if (! filter_var(env('MOH_SSL_VERIFY', false), FILTER_VALIDATE_BOOL)) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                $this->error("خطأ HTTP {$response->status()} من $url");
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            $this->error('استثناء: '.$e->getMessage());
            return null;
        }
    }

    private function parseProducts(array $aaData): array
    {
        $rows = [];
        foreach ($aaData as $item) {
            if (! is_array($item) || count($item) < 5) {
                continue;
            }

            $name = $this->cleanText($item[0] ?? '');
            if ($name === '') {
                continue;
            }

            $productId = null;
            if (preg_match('/ProductsId\/(\d+)/', (string) ($item[5] ?? ''), $m)) {
                $productId = (int) $m[1];
            }

            $rows[] = [
                '_key' => $this->normalize($name),
                'trade_name' => mb_substr($name, 0, 255),
                'manufacturer' => $this->nullableText($item[1] ?? null),
                'dosage_form' => $this->nullableText($item[2] ?? null),
                'product_class' => $this->nullableText($item[3] ?? null),
                'origin' => $this->nullableText($item[4] ?? null),
                'moh_product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function parsePrices(array $aaData): array
    {
        $rows = [];
        foreach ($aaData as $item) {
            if (! is_array($item) || count($item) < 6) {
                continue;
            }

            $name = $this->cleanText($item[0] ?? '');
            if ($name === '') {
                continue;
            }

            $drugId = null;
            if (preg_match('/DrugsId\/(\d+)/', (string) ($item[6] ?? ''), $m)) {
                $drugId = (int) $m[1];
            }

            $updatedAt = null;
            if (preg_match("/flash'>(\d{4}-\d{2}-\d{2})/", (string) ($item[6] ?? ''), $m)) {
                $updatedAt = $m[1];
            }

            $price = null;
            $rawPrice = (string) ($item[2] ?? '');
            if (is_numeric($rawPrice)) {
                $price = (float) $rawPrice;
            }

            $rows[] = [
                '_key' => $this->normalize($name),
                'trade_name' => mb_substr($name, 0, 255),
                'generic_name' => $this->nullableText($item[1] ?? null),
                'official_price' => $price,
                'packaging' => $this->nullableText($item[3] ?? null),
                'company' => $this->nullableText($item[4] ?? null),
                'availability' => $this->nullableText($item[5] ?? null),
                'moh_drug_id' => $drugId,
                'price_updated_at' => $updatedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->cleanText((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function normalize(string $name): string
    {
        return mb_strtoupper($this->cleanText($name));
    }
}
