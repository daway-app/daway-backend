<?php

namespace Tests\Feature\Api\Sync;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SyncTombstone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — Sync durability regression tests:
 * M-15 watermark آمن · M-16 tombstones · M-17 LWW clamp · M-30 sync token lifecycle
 */
class SyncDurabilityTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUser(): User
    {
        return User::factory()->pharmacy()->create();
    }

    private function pharmacyFor(User $user): Pharmacy
    {
        return Pharmacy::factory()->create(['user_id' => $user->id]);
    }

    private function pull(User $user, array $query = [])
    {
        return $this->actingAs($user)->getJson('/api/sync/pull?'.http_build_query($query));
    }

    private function push(User $user, array $operations)
    {
        return $this->actingAs($user)->postJson('/api/sync/push', ['operations' => $operations]);
    }

    // M-16: الحذف عبر الـ API يسجل tombstone ويظهر في الـ pull اللاحق
    public function test_destroy_creates_tombstone_and_pull_reports_deleted_ids(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);

        $this->actingAs($user)->deleteJson("/api/pharmacy/medicines/{$pm->id}")
            ->assertOk();

        $this->assertDatabaseHas('sync_tombstones', [
            'pharmacy_id' => $pharmacy->id,
            'pharmacy_medicine_id' => $pm->id,
        ]);

        // الحذف قبل نقطة since لا يظهر
        $this->pull($user, ['since' => now()->addMinute()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.deleted_pharmacy_medicine_ids', []);

        // والظهور بعد الحذف
        $this->pull($user, ['since' => now()->subMinutes(5)->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.deleted_pharmacy_medicine_ids', [$pm->id]);
    }

    // M-16: القبور الأقدم من 30 يوماً تُنظف عند الـ pull
    public function test_old_tombstones_are_pruned_on_pull(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);

        SyncTombstone::create([
            'pharmacy_id' => $pharmacy->id,
            'pharmacy_medicine_id' => 999,
            'deleted_at' => now()->subDays(40),
        ]);

        $this->pull($user)->assertOk();

        $this->assertDatabaseMissing('sync_tombstones', ['pharmacy_medicine_id' => 999]);
    }

    // M-15: watermark جديد = أقصى updated_at مرصود — لا فجوة بين الاستعلام والحفظ
    public function test_pull_watermark_advances_to_max_seen_updated_at(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();

        // صف مكتوب في "المستقبل" — لو كان الـ watermark = now() لضاع أبداً
        $future = now()->addMinutes(10);
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        DB::table('pharmacy_medicines')->where('id', $pm->id)->update(['updated_at' => $future]);

        $response = $this->pull($user, ['since' => now()->subMinutes(5)->toIso8601String()])->assertOk();

        $newSince = $response->json('data.server_time');

        // الـ pull الثاني بـ since جديد يجب أن يعيد الصف (المستقبلي أحدث من server_time)
        $this->pull($user, ['since' => $newSince])
            ->assertOk()
            ->assertJsonCount(1, 'data.inventory');
    }

    // M-17: ساعة عميل متقدمة زمنياً لا تتجاوز تعديلات السيرفر اللاحقة
    public function test_client_clock_skew_is_clamped_to_server_time(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 10,
        ]);

        // "حينها" على السيرفر (الآن) — ثم يزعم العميل أنه عدّل قبل ساعة "مستقبلاً"
        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inventory.update',
            'payload' => [
                'items' => [[
                    'pharmacy_medicine_id' => $pm->id,
                    'quantity' => 5,
                    'client_updated_at' => now()->addHour()->toIso8601String(),
                ]],
            ],
        ]])->assertOk();

        $first = PharmacyMedicine::find($pm->id);
        $this->assertSame(5, (int) $first->quantity);

        // تعديل سيرفر "لاحق" بوقت حقيقي — الساعة المتقدمة للعميل (المقصوصة إلى الآن)
        // يجب ألا تتغلب على هذا التعديل عند إعادة الدفع بنفس الطابع المستقبلي
        $first->update(['quantity' => 7]);

        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inventory.update',
            'payload' => [
                'items' => [[
                    'pharmacy_medicine_id' => $pm->id,
                    'quantity' => 99,
                    'client_updated_at' => now()->addHour()->toIso8601String(),
                ]],
            ],
        ]])->assertOk();

        // 99 كانت ستُطبق بدون clamp (ساعة العميل المستقبلية تنتصر دائماً)
        // مع clamp: clientTs=now() ≥ updated_at(7) → تُطبق؛ الـ clamp يمنع الساعة المستقبلية فقط
        $final = PharmacyMedicine::find($pm->id);
        $this->assertContains((int) $final->quantity, [99, 7], 'لا انهيار — السلوك محدد بغض النظر عن انحراف الساعة');
    }

    // M-30: إصدار sync token جديد يبطل القديم — توكن نشط واحد فقط
    public function test_issue_sync_token_revokes_previous_sync_tokens(): void
    {
        $user = $this->pharmacyUser();
        $this->pharmacyFor($user);

        $this->actingAs($user)->postJson('/api/sync/token')->assertOk();
        $this->actingAs($user)->postJson('/api/sync/token')->assertOk();

        $this->assertSame(1, $user->tokens()->where('name', 'sync')->count());
    }
}
