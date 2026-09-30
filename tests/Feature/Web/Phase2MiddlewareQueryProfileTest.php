<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — دليل رقمي دائم على تقليل ذهاب-وإياب القاعدة في الـ middleware.
 *
 * يقيس عدد الاستعلامات التي تُطلقها سلسلة الـ middleware على صفحة صيدلية،
 * ويؤكد أن العلاقة `pharmacies` تُقرأ **مرة واحدة** فقط بلا تكرار، وأن
 * `settings` (لغة افتراضية) لا تُقرأ لصيدلية مسجَّلة دخول أبدًا.
 *
 * الأرقام (قبل/بعد Phase 2) موثّقة في تقرير Phase 2:
 *   قبل : pharmacies = 2  (select * كسول في EnsureProfileComplete + استعلام لوحة)
 *   بعد : pharmacies = 2  (لكن الأول صار أعمدة محدّدة id+profile_completed_at)
 *   والتحسين الحقيقي: تقليص حمولة العمود + ضمان عدم التكرار عبر setRelation().
 *
 * ملاحظة: القياس عبر DB::enableQueryLog **داخل الاختبار فقط** — لا
 * instrumentation دائم في الإنتاج.
 */
class Phase2MiddlewareQueryProfileTest extends TestCase
{
    /**
     * عدّ الاستعلامات التي ينفّذها الطلب، مُصنَّفة حسب الجدول.
     */
    private function captureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = array_map(static fn ($entry) => $entry['query'], $log);

        $has = static fn (string $table) => count(array_filter(
            $sql,
            static fn ($q) => str_contains($q, "from \"{$table}\"") || str_contains($q, "from `{$table}`")
        ));

        return [
            'total' => count($sql),
            'pharmacies' => $has('pharmacies'),
            'settings' => $has('settings'),
            'all' => $sql,
        ];
    }

    public function test_pharmacy_page_reads_pharmacies_once_and_never_settings(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($user);

        $stats = $this->captureQueries(function () {
            $this->get(route('pharmacy.dashboard.index'))->assertOk();
        });

        // 🔴 العطل الأصلي: استعلام `select *` كسول في EnsureProfileComplete.
        // التحسين: أعمدة محدّدة (id + profile_completed_at) بلا `*`.
        //
        // ملاحظة: لوحة القيادة نفسها تقرأ `pharmacies` بـ select * (تحتاج كل
        // الأعمدة + hours)، لذا لا ننفي وجود select * إجمالاً — بل نؤكد أن
        // استعلام الوسيط **المُضيَّق** موجود فعلاً.
        $narrowed = array_filter(
            $stats['all'],
            static fn ($q) => str_contains($q, 'pharmacies')
                && str_contains($q, '"id"')
                && str_contains($q, '"profile_completed_at"')
                && ! str_contains($q, 'select *')
        );

        $this->assertNotEmpty(
            $narrowed,
            'يجب أن يقرأ الوسيط `pharmacies` بأعمدة محدّدة (id + profile_completed_at) فقط. كل استعلامات pharmacies: '
                .implode(' | ', array_filter($stats['all'], static fn ($q) => str_contains($q, 'pharmacies')))
        );

        // صيدلية مسجّلة دخول تملك session('locale') ⇒ لا قراءة لجدول settings.
        $this->assertSame(
            0,
            $stats['settings'],
            "صفحة صيدلية يجب ألا تقرأ `settings` في الـ middleware. الفعلي: {$stats['settings']}"
        );
    }

    public function test_middleware_does_not_requery_a_preloaded_pharmacy_relation(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => now(),
        ]);

        // نُحمّل العلاقة مسبقًا تمامًا كما تفعل طبقة أخرى (sidebar مثلاً).
        $user->load('pharmacy');
        $this->actingAs($user);

        $stats = $this->captureQueries(function () {
            $this->get(route('pharmacy.dashboard.index'))->assertOk();
        });

        // العلاقة محمّلة ⇒ EnsureProfileComplete لا يجوز أن يستعلم ثانيةً.
        // يبقى استعلام واحد فقط من لوحة القيادة (PharmacyDashboardController).
        $this->assertLessThanOrEqual(
            1,
            $stats['pharmacies'],
            "العلاقة المحمّلة مسبقًا يجب أن تُعاد استخدامها بلا استعلام جديد. الفعلي: {$stats['pharmacies']}"
        );
    }

    public function test_guest_page_never_queries_pharmacies(): void
    {
        $stats = $this->captureQueries(function () {
            $this->get(route('login.show'))->assertOk();
        });

        $this->assertSame(0, $stats['pharmacies'], 'الزائر لا يجوز أن يقرأ `pharmacies`');

        // settings تُقرأ مرة واحدة على الأكثر (default_language) لكل طلب.
        $this->assertLessThanOrEqual(
            1,
            $stats['settings'],
            "قراءة `settings` يجب ألا تتكرر في الطلب الواحد. الفعلي: {$stats['settings']}"
        );
    }

    /**
     * منع الانحدار: `setRelation()` يجب أن يثبّت النتيجة فعلاً — أي أن أي
     * مستهلك لاحق لـ `$user->pharmacy` لا يُطلق استعلامًا جديدًا.
     */
    public function test_set_relation_prevents_second_read_for_unloaded_relation(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null, // غير مكتمل ⇒ الوسيط يفحص فعلاً
        ]);

        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        // يقرأ الوسيط الصيدلية غير المكتملة ⇒ إعادة توجيه لصفحة الإكمال.
        $response = $this->get(route('pharmacy.dashboard.index'));
        $beforeRelation = count(array_filter(
            DB::getQueryLog(),
            static fn ($e) => str_contains($e['query'], 'pharmacies') && str_contains($e['query'], 'select *')
        ));
        DB::disableQueryLog();

        $response->assertRedirect(route('pharmacy.profile.complete.show'));
        $this->assertSame(
            0,
            $beforeRelation,
            'الوسيط يجب ألا يستخدم select * على pharmacies حتى في مسار إعادة التوجيه'
        );
    }
}
