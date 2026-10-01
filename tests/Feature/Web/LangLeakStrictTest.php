<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * Strict language-leak probe.
 *
 * The loose probe produced a false positive: the inline i18n payload
 * (window.acBarcodeI18n = {...}) contains JSON *keys* that look like
 * translation keys ("barcode.status.pending"). We strip <script> blocks
 * before scanning, so only keys that leaked into *visible* markup are flagged.
 */
class LangLeakStrictTest extends TestCase
{
    private function pharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'delivered_at' => now(),
        ]);

        return [$user, $pharmacy];
    }

    public function test_no_untranslated_keys_are_visible_on_accounting_pages(): void
    {
        [$user, $pharmacy] = $this->pharmacy();

        $routes = [
            '/pharmacy/accounting',
            '/pharmacy/accounting/sales',
            '/pharmacy/accounting/sales/create',
            '/pharmacy/accounting/refunds',
            '/pharmacy/accounting/cash',
        ];

        $leaks = [];

        foreach ($routes as $url) {
            $html = $this->actingAs($user)->get($url)->getContent();

            // Strip <script>...</script> — that is where the JSON i18n payload lives.
            $visible = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

            if (preg_match_all('/\b(accounting|auth|validation|pagination)\.[a-z0-9_]+(\.[a-z0-9_]+)+\b/i', $visible, $m)) {
                foreach (array_unique($m[0]) as $key) {
                    $leaks[] = $url.' => '.$key;
                }
            }
        }

        $this->assertSame([], $leaks, "Visible language keys leaked:\n".implode("\n", $leaks));
    }
}
