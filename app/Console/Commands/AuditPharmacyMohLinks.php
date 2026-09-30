<?php

namespace App\Console\Commands;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuditPharmacyMohLinks extends Command
{
    protected $signature = 'audit:pharmacy-moh-links';

    protected $description = 'Read-only audit of pharmacy_medicines to MOH medicine matching';

    public function handle(): int
    {
        $this->info('=== MATCHING AUDIT: pharmacy_medicines to MohMedicine ===');
        $this->info('READ-ONLY - No database modifications');

        $mappingPath = base_path('database/data/chatbot_medicines.json');
        $hasMappingFile = File::exists($mappingPath);

        $this->info("\nMapping file: " . ($hasMappingFile ? "FOUND at database/data/chatbot_medicines.json" : "NOT FOUND"));

        // Collect all pharmacy_medicines
        $rows = PharmacyMedicine::select(['id', 'medicine_id', 'pharmacy_id', 'price', 'quantity', 'is_available'])
            ->get();

        $totalRows = $rows->count();

        $results = [
            'exact_match' => [],
            'mapped_match' => [],
            'ambiguous' => [],
            'unmatched' => [],
        ];

        // Process each row
        foreach ($rows as $row) {
            $medicine = Medicine::find($row->medicine_id);

            if (! $medicine) {
                $results['unmatched'][] = [
                    'pharmacy_medicine_id' => $row->id,
                    'medicine_id' => $row->medicine_id,
                    'trade_name' => null,
                    'trade_name_ar' => null,
                    'candidates' => [],
                    'strategy' => 'medicine_not_found',
                ];
                continue;
            }

            $tradeNameEn = $medicine->trade_name;
            $tradeNameAr = $medicine->trade_name_ar;

            $candidates = [];
            $matchedBy = null;

            // Strategy 1: Exact trade_name match (English)
            $exactMatches = MohMedicine::where('trade_name', $tradeNameEn)->get();

            if ($exactMatches->count() === 1) {
                $results['exact_match'][] = [
                    'pharmacy_medicine_id' => $row->id,
                    'medicine_id' => $row->medicine_id,
                    'trade_name' => $tradeNameEn,
                    'trade_name_ar' => $tradeNameAr,
                    'candidates' => $exactMatches->map(fn($m) => ['id' => $m->id, 'trade_name' => $m->trade_name])->all(),
                    'strategy' => 'exact_trade_name',
                ];
                continue;
            }

            if ($exactMatches->count() > 1) {
                $candidates = $exactMatches->map(fn($m) => ['id' => $m->id, 'trade_name' => $m->trade_name])->all();
            }

            // Strategy 2: Arabic to English mapping via chatbot_medicines.json
            if ($tradeNameAr && $hasMappingFile) {
                $mappingMatches = $this->lookupArabicMapping($tradeNameAr, $tradeNameEn);

                if (count($mappingMatches) === 1) {
                    $existingExact = ($candidates ?? []);
                    $alreadyHasThis = false;
                    foreach ($existingExact as $c) {
                        if ($c['id'] === $mappingMatches[0]['id']) {
                            $alreadyHasThis = true;
                            break;
                        }
                    }

                    if (! $alreadyHasThis) {
                        $candidates = array_merge($candidates ?? [], $mappingMatches);
                    }
                } elseif (count($mappingMatches) > 1) {
                    foreach ($mappingMatches as $m) {
                        $alreadyHas = false;
                        foreach ($candidates ?? [] as $existing) {
                            if ($existing['id'] === $m['id']) {
                                $alreadyHas = true;
                                break;
                            }
                        }
                        if (! $alreadyHas) {
                            $candidates[] = $m;
                        }
                    }
                }
            }

            // Strategy 3: Check if Arabic trade_name matches any MOH (unlikely but possible)
            if ($tradeNameAr) {
                $arabicMatches = MohMedicine::where('trade_name', 'like', "%{$tradeNameAr}%")->get();
                if ($arabicMatches->count() === 1) {
                    $alreadyHas = false;
                    foreach ($candidates ?? [] as $c) {
                        if ($c['id'] === $arabicMatches->first()->id) {
                            $alreadyHas = true;
                            break;
                        }
                    }
                    if (! $alreadyHas) {
                        $candidates[] = [
                            'id' => $arabicMatches->first()->id,
                            'trade_name' => $arabicMatches->first()->trade_name,
                        ];
                    }
                } elseif ($arabicMatches->count() > 1) {
                    foreach ($arabicMatches as $am) {
                        $alreadyHas = false;
                        foreach ($candidates ?? [] as $existing) {
                            if ($existing['id'] === $am->id) {
                                $alreadyHas = true;
                                break;
                            }
                        }
                        if (! $alreadyHas) {
                            $candidates[] = ['id' => $am->id, 'trade_name' => $am->trade_name];
                        }
                    }
                }
            }

            // Determine final status
            $uniqueCandidates = $this->deduplicateCandidates($candidates);

            if (count($uniqueCandidates) === 1) {
                $results['mapped_match'][] = [
                    'pharmacy_medicine_id' => $row->id,
                    'medicine_id' => $row->medicine_id,
                    'trade_name' => $tradeNameEn,
                    'trade_name_ar' => $tradeNameAr,
                    'candidates' => $uniqueCandidates,
                    'strategy' => 'mapped',
                ];
            } elseif (count($uniqueCandidates) > 1) {
                $results['ambiguous'][] = [
                    'pharmacy_medicine_id' => $row->id,
                    'medicine_id' => $row->medicine_id,
                    'trade_name' => $tradeNameEn,
                    'trade_name_ar' => $tradeNameAr,
                    'candidates' => $uniqueCandidates,
                    'strategy' => 'ambiguous_multiple_matches',
                ];
            } else {
                $results['unmatched'][] = [
                    'pharmacy_medicine_id' => $row->id,
                    'medicine_id' => $row->medicine_id,
                    'trade_name' => $tradeNameEn,
                    'trade_name_ar' => $tradeNameAr,
                    'candidates' => [],
                    'strategy' => 'no_match_found',
                ];
            }
        }

        // Output results
        $this->info("\n=== MATCHING RESULTS ===");
        $this->info("Total inventory rows: {$totalRows}");
        $this->info("Exact matches: " . count($results['exact_match']));
        $this->info("Mapped matches: " . count($results['mapped_match']));
        $this->info("Ambiguous: " . count($results['ambiguous']));
        $this->info("Unmatched: " . count($results['unmatched']));

        // Catalog-level classification (medicines table vs MOH trade_name).
        // Read-only aggregates only — no writes.
        $totalMedicines = DB::table('medicines')->count();
        $totalMoh = DB::table('moh_medicines')->count();
        $this->info("\n=== CATALOG COUNTS ===");
        $this->info("Total medicines: {$totalMedicines}");
        $this->info("Total MOH medicines: {$totalMoh}");

        $medicineNames = DB::table('medicines')->select('trade_name')->get();
        $exactlyOne = 0;
        $ambiguousMed = 0;
        $noMatchMed = 0;
        foreach ($medicineNames as $med) {
            $c = DB::table('moh_medicines')->where('trade_name', $med->trade_name)->count();
            if ($c === 1) {
                $exactlyOne++;
            } elseif ($c > 1) {
                $ambiguousMed++;
            } else {
                $noMatchMed++;
            }
        }
        $this->info("Medicines with exactly one MOH match: {$exactlyOne}");
        $this->info("Medicines with ambiguous MOH match: {$ambiguousMed}");
        $this->info("Medicines with no MOH match: {$noMatchMed}");

        // Inventory link status (guarded for pre-migration schema).
        $hasLinkColumn = Schema::hasColumn('pharmacy_medicines', 'moh_medicine_id');
        if ($hasLinkColumn) {
            $alreadyLinked = DB::table('pharmacy_medicines')->whereNotNull('moh_medicine_id')->count();
            $nullLinks = DB::table('pharmacy_medicines')->whereNull('moh_medicine_id')->count();
            $uniqueMohLinks = DB::table('pharmacy_medicines')->whereNotNull('moh_medicine_id')->distinct()->count('moh_medicine_id');
            $uniqueMedIds = DB::table('pharmacy_medicines')->distinct()->count('medicine_id');
            $this->info("\n=== INVENTORY LINK STATUS ===");
            $this->info("Total pharmacy_medicines: {$totalRows}");
            $this->info("Already linked to MOH: {$alreadyLinked}");
            $this->info("NULL moh_medicine_id: {$nullLinks}");
            $this->info("Unique medicine_id in inventory: {$uniqueMedIds}");
            $this->info("Unique moh_medicine_id in inventory: {$uniqueMohLinks}");
            $this->info("Inventory with unique MOH mapping: " . count($results['exact_match']));
            $this->info("Inventory ambiguous: " . count($results['ambiguous']));
            $this->info("Inventory unmatched: " . count($results['unmatched']));
        } else {
            $this->info("\n=== INVENTORY LINK STATUS ===");
            $this->info("Already linked to MOH: N/A (moh_medicine_id column not present — migration not run)");
            $this->info("Inventory with unique MOH mapping: " . count($results['exact_match']));
            $this->info("Inventory ambiguous: " . count($results['ambiguous']));
            $this->info("Inventory unmatched: " . count($results['unmatched']));
        }

        // Duplicate keys — real counts only, identity stays moh_medicines.id.
        $dupTrade = DB::table('moh_medicines')
            ->select('trade_name', DB::raw('COUNT(*) as c'))
            ->groupBy('trade_name')->having('c', '>', 1)->count();
        $dupProduct = DB::table('moh_medicines')
            ->select('moh_product_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('moh_product_id')
            ->groupBy('moh_product_id')->having('c', '>', 1)->count();
        $dupDrug = DB::table('moh_medicines')
            ->select('moh_drug_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('moh_drug_id')
            ->groupBy('moh_drug_id')->having('c', '>', 1)->count();
        $this->info("\n=== DUPLICATE KEYS (MOH) ===");
        $this->info("Duplicate trade_names: {$dupTrade}");
        $this->info("Duplicate moh_product_id: {$dupProduct}");
        $this->info("Duplicate moh_drug_id: {$dupDrug}");

        // Unmatched samples
        if (! empty($results['unmatched'])) {
            $this->warn("\n=== UNMATCHED SAMPLES ===");
            foreach (array_slice($results['unmatched'], 0, 10) as $item) {
                $this->line("ID: {$item['pharmacy_medicine_id']} | Local: {$item['trade_name']}" . ($item['trade_name_ar'] ? " / {$item['trade_name_ar']}" : '') . " | Strategy: {$item['strategy']}");
            }
        }

        // Ambiguous samples
        if (! empty($results['ambiguous'])) {
            $this->error("\n=== AMBIGUOUS SAMPLES ===");
            foreach ($results['ambiguous'] as $item) {
                $this->line("ID: {$item['pharmacy_medicine_id']} | Local: {$item['trade_name']}");
                $this->line("  Candidates: " . json_encode($item['candidates'], JSON_UNESCAPED_UNICODE));
            }
        }

        // Mapped match samples
        if (! empty($results['mapped_match'])) {
            $this->info("\n=== MAPPED MATCH SAMPLES (first 10) ===");
            foreach (array_slice($results['mapped_match'], 0, 10) as $item) {
                $this->line("ID: {$item['pharmacy_medicine_id']} | Local: {$item['trade_name']} | MOH ID: {$item['candidates'][0]['id']} | Strategy: {$item['strategy']}");
            }
        }

        // Specific investigation for Panadol
        $this->info("\n=== SPECIFIC INVESTIGATION: Panadol / بانادول ===");
        $this->investigateTradeName('Panadol', $hasMappingFile);

        return self::SUCCESS;
    }

    private function investigateTradeName(string $name, bool $hasMapping): void
    {
        // Exact MOH matches
        $mohMatches = MohMedicine::where('trade_name', $name)->get();
        $this->info("MOH exact matches for '{$name}': " . $mohMatches->count());
        foreach ($mohMatches as $m) {
            $this->line("  - MOH ID: {$m->id}, trade_name: {$m->trade_name}, manufacturer: {$m->manufacturer}");
        }

        // Arabic version
        $arName = 'بانادول';
        $mohArabicMatches = MohMedicine::where('trade_name', 'like', "%{$arName}%")->get();
        $this->info("MOH matches for Arabic '{$arName}': " . $mohArabicMatches->count());
        foreach ($mohArabicMatches as $m) {
            $this->line("  - MOH ID: {$m->id}, trade_name: {$m->trade_name}");
        }

        if ($hasMapping) {
            $this->info("Arabic mapping in chatbot_medicines.json:");
            $mappingResults = $this->lookupArabicMapping($arName, $name);
            $this->info("  Mapped MOH count: " . count($mappingResults));
            foreach ($mappingResults as $r) {
                $this->line("  - MOH ID: {$r['id']}, trade_name: {$r['trade_name']}");
            }
        }
    }

    private function lookupArabicMapping(string $arabicName, string $englishName): array
    {
        $path = base_path('database/data/chatbot_medicines.json');
        if (! File::exists($path)) {
            return [];
        }

        $needle = $this->normalizeArabic($arabicName);
        $hydras = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false && count($hydras) < 50) {
                $line = trim($line, " \t\r\n,");
                if ($line === '' || $line === '[' || $line === ']') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }

                $records = array_is_list($record) ? $record : [$record];
                foreach ($records as $candidate) {
                    if (! is_array($candidate) || count($hydras) >= 50) {
                        continue;
                    }

                    $aliases = array_values(array_filter($candidate['aliases'] ?? [], 'is_string'));
                    foreach ($aliases as $alias) {
                        $normalized = $this->normalizeArabic($alias);
                        if ($this->normalizeArabic($alias) === $needle || str_contains($normalized, $needle)) {
                            // Find MOH by name from mapping
                            $nameEn = $candidate['name_en'] ?? '';
                            if ($nameEn) {
                                $moh = MohMedicine::where('trade_name', 'like', "%{$nameEn}%")->first();
                                if ($moh && ! in_array($moh->id, array_column($hydras, 'id'))) {
                                    $hydras[] = ['id' => $moh->id, 'trade_name' => $moh->trade_name];
                                }
                            }
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // ignore
        } finally {
            fclose($handle);
        }

        return $hydras;
    }

    private function normalizeArabic(string $s): string
    {
        $s = strtr($s, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
        ]);
        return mb_strtolower($s);
    }

    private function deduplicateCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $c) {
            $found = false;
            foreach ($unique as $u) {
                if ($u['id'] === $c['id']) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $unique[] = $c;
            }
        }
        return $unique;
    }
}