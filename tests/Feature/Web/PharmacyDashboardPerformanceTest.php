<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 — حراسة أداء لوحة الصيدلية.
 *
 * يثبّت أن اللوحة:
 *  1. تُبنى وتُخدَم بلا خطأ (لا regression في العرض).
 *  2. لا تُطلق استعلام count/fetch للـ pagination الميت (كان paginate(5)
 *     لبيانات غير مستخدمة في الـ view ⇒ 3 استعلامات مهدورة).
 *
 * القياس داخل الاختبار فقط — لا instrumentation دائم في الإنتاج.
 */
class PharmacyDashboardPerformanceTest extends TestCase
{
    private function seedPharmacy(): User
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => now(),
        ]);

        foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            PharmacyHour::factory()->create([
                'pharmacy_id' => $pharmacy->id,
                'day_of_week' => $day,
            ]);
        }

        PharmacyMedicine::factory()->count(12)->create(['pharmacy_id' => $pharmacy->id]);
        Rating::factory()->count(4)->create(['pharmacy_id' => $pharmacy->id]);

        return $user;
    }

    public function test_dashboard_renders_and_has_no_dead_pagination_queries(): void
    {
        $user = $this->seedPharmacy();
        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('pharmacy.dashboard.index'));
        $sql = array_map(fn ($e) => $e['query'], DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();

        // كان الـ paginate(5) الميت يُنتج استعلام عدد منفصلًا:
        //   select count(*) as "aggregate" from "pharmacy_medicines" ...
        $countQueries = array_filter(
            $sql,
            fn ($q) => str_contains($q, 'count(*)')
                && str_contains($q, 'pharmacy_medicines')
                && ! str_contains($q, 'SUM(')
        );

        $this->assertCount(
            0,
            $countQueries,
            'اللوحة يجب ألا تُطلق استعلام count منفصلًا لـ pharmacy_medicines (pagination ميت). الفعلي: '
                .implode(' | ', $countQueries)
        );

        // حدّ أعلى واضح: لا يجوز أن يتجاوز عدد استعلامات اللوحة 13.
        // (12 بعد Phase 3 + 1 استعلام عدّ الإشعارات من Phase 4 في الـlayout.)
        $this->assertLessThanOrEqual(
            13,
            count($sql),
            'عدد استعلامات لوحة الصيدلية يجب أن يبقى ≤ 13. الفعلي: '.count($sql)
        );
    }

    public function test_dashboard_still_shows_inventory_stats(): void
    {
        $user = $this->seedPharmacy();
        $this->actingAs($user);

        $response = $this->get(route('pharmacy.dashboard.index'))->assertOk();

        // الإحصاءات لا تزال تُعرض (لا تغيير في السلوك).
        $response->assertSee(__('pharmacy.dashboard.stat_total'));
        $response->assertSee(__('pharmacy.dashboard.stat_available'));
        $response->assertSee(__('pharmacy.dashboard.heading'));
    }
}
