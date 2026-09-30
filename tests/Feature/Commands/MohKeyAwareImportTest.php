<?php

namespace Tests\Feature\Commands;

use App\Models\MohMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Key-aware MOH import strategy (Phase 2–5):
 *  - Step A: match by moh_product_id
 *  - Step B: match by moh_drug_id (independent of A)
 *  - Step C/E: product→A + drug→B with A≠B = conflict, no silent merge
 *  - Step D: update preserves moh_medicines.id; new keys create new rows
 */
class MohKeyAwareImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'moh_').'.json';
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function row(?int $productId, ?int $drugId, string $tradeName = 'Trade'): array
    {
        return [
            'trade_name' => $tradeName.' '.$productId.'-'.$drugId,
            'manufacturer' => 'M',
            'dosage_form' => 'Tablet',
            'product_class' => 'Pharmaceutical',
            'origin' => 'Local',
            'moh_product_id' => $productId,
            'generic_name' => 'G',
            'official_price' => null,
            'packaging' => null,
            'company' => null,
            'availability' => null,
            'moh_drug_id' => $drugId,
            'price_updated_at' => null,
        ];
    }

    public function test_product_id_only_and_drug_id_only_records_import(): void
    {
        $path = $this->fixturePath([
            $this->row(91001, null, 'ProdOnly'),
            $this->row(null, 92001, 'DrugOnly'),
            $this->row(91002, 92002, 'BothKeys'),
        ]);

        $this->artisan('moh:import', ['--file' => $path])->assertExitCode(0);

        $this->assertSame(3, MohMedicine::count());
        $this->assertNotNull(MohMedicine::where('moh_product_id', 91001)->first());
        $this->assertNotNull(MohMedicine::where('moh_drug_id', 92001)->first());
        $both = MohMedicine::where('moh_product_id', 91002)->first();
        $this->assertSame(92002, (int) $both->moh_drug_id);
    }

    public function test_repeat_import_preserves_ids_and_updates_in_place(): void
    {
        $path = $this->fixturePath([$this->row(93101, 93201, 'Stable')]);
        $this->artisan('moh:import', ['--file' => $path])->assertExitCode(0);
        $firstId = MohMedicine::where('moh_product_id', 93101)->value('id');

        // Re-import same keys with changed trade_name: same row updated, id stable.
        $path2 = $this->fixturePath([$this->row(93101, 93201, 'StableRenamed')]);
        $this->artisan('moh:import', ['--file' => $path2])->assertExitCode(0);

        $this->assertSame(1, MohMedicine::count());
        $row = MohMedicine::where('moh_product_id', 93101)->first();
        $this->assertSame($firstId, $row->id);
        $this->assertStringContainsString('StableRenamed', $row->trade_name);
    }

    public function test_new_moh_record_gets_new_id(): void
    {
        $this->artisan('moh:import', ['--file' => $this->fixturePath([$this->row(94101, null, 'First')])])
            ->assertExitCode(0);
        $this->artisan('moh:import', ['--file' => $this->fixturePath([$this->row(94102, null, 'Second')])])
            ->assertExitCode(0);

        $this->assertSame(2, MohMedicine::count());
        $this->assertNotSame(
            MohMedicine::where('moh_product_id', 94101)->value('id'),
            MohMedicine::where('moh_product_id', 94102)->value('id')
        );
    }

    public function test_cross_field_conflict_is_reported_not_merged(): void
    {
        MohMedicine::create(['trade_name' => 'Row A', 'moh_product_id' => 95101]);
        MohMedicine::create(['trade_name' => 'Row B', 'moh_drug_id' => 95201]);
        $idA = MohMedicine::where('moh_product_id', 95101)->value('id');
        $idB = MohMedicine::where('moh_drug_id', 95201)->value('id');

        $path = $this->fixturePath([$this->row(95101, 95201, 'ConflictRow')]);

        $this->artisan('moh:import', ['--file' => $path, '--report-conflicts' => true])
            ->expectsOutputToContain('CONFLICT')
            ->assertExitCode(1);

        // No merge: both originals untouched, no third row created.
        $this->assertSame(2, MohMedicine::count());
        $this->assertSame('Row A', MohMedicine::find($idA)->trade_name);
        $this->assertSame('Row B', MohMedicine::find($idB)->trade_name);
    }

    public function test_record_without_any_key_is_skipped(): void
    {
        $path = $this->fixturePath([
            $this->row(null, null, 'NoKey'),
            $this->row(96101, null, 'HasKey'),
        ]);

        $this->artisan('moh:import', ['--file' => $path])->assertExitCode(0);

        $this->assertSame(1, MohMedicine::count());
        $this->assertNotNull(MohMedicine::where('moh_product_id', 96101)->first());
    }
}
