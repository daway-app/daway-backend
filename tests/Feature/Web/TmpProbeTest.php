<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

class TmpProbeTest extends TestCase
{
    public function test_probe(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 120; $i++) {
            $m = Medicine::factory()->create([
                'trade_name' => 'INV-MED-'.$i,
                'active_ingredient' => 'ING-'.$i,
            ]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $m->id,
                'price' => 5,
                'quantity' => 20,
            ]);
        }

        // q=INV-MED matches INV-MED-0..119 AND INV-MED-1,10..19 etc = all 120
        foreach (['q=INV-MED', 'q=INV-MED-', 'q=INV-MED-1'] as $qs) {
            $html = $this->actingAs($user)->get('/pharmacy/inventory?'.$qs)->assertOk()->getContent();
            $rows = substr_count($html, 'data-status=');
            $hasPag = substr_count($html, 'pagination-wrapper');
            $urls = [];
            preg_match_all('/href="([^"]*inventory[^"]*page=[^"]*)"/', $html, $m);
            $urls = array_slice($m[1] ?? [], 0, 4);
            fwrite(STDERR, "\n[$qs] rows=$rows pagination=$hasPag urls=".json_encode($urls));
        }
        $this->assertTrue(true);
    }
}
