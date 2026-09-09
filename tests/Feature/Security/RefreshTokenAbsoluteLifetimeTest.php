<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H-13: العمر المطلق للتوكن (absolute lifetime) — أي توكن أقدم من 30 يوماً
 * يُرفض ويُحذف حتى لو كان rotate؛ التوكن الصحيح يدور (rotation) ويُحذف القديم.
 */
class RefreshTokenAbsoluteLifetimeTest extends TestCase
{
    public function test_token_older_than_30_days_is_rejected_and_deleted(): void
    {
        $user = User::factory()->patient()->create();
        $token = $user->createToken('auth_token');

        // محاكاة عمر سلسلة الـ refresh: بداية السلسلة 31 يوماً — تجاوز الحد المطلق (30 يوماً)
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['chain_started_at' => now()->subDays(31)->format('Y-m-d H:i:s')]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/refresh-token')
            ->assertStatus(401);

        // كل توكنات السلسلة المنتهية تُحذف من قاعدة البيانات
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_rotation_does_not_reset_the_absolute_clock(): void
    {
        $user = User::factory()->patient()->create();

        // سلسلة بدأت قبل 25 يوماً — الـ rotation لا يعيّن العدّاد من الصفر
        $oldToken = $user->createToken('auth_token');
        DB::table('personal_access_tokens')
            ->where('id', $oldToken->accessToken->id)
            ->update(['chain_started_at' => now()->subDays(25)->format('Y-m-d H:i:s')]);

        $response = $this->withHeader('Authorization', 'Bearer '.$oldToken->plainTextToken)
            ->postJson('/api/refresh-token');
        $response->assertOk();

        // التوكن الجديد ورث بداية السلسلة (25 يوماً) — لم يُصفَّر
        // (المقارنة بفارق دقيقتين للتسامح مع زمن تنفيذ الطلب)
        $newTokenRow = DB::table('personal_access_tokens')->latest('id')->first();
        $expectedStart = now()->subDays(25);
        $actualStart = \Carbon\Carbon::parse($newTokenRow->chain_started_at);
        $this->assertTrue(
            $actualStart->between($expectedStart->copy()->subMinutes(2), $expectedStart->copy()->addMinutes(2)),
            'التوكن الجديد يجب أن يورّث chain_started_at (~25 يوماً) بدل تصفيره، القيمة: '.$newTokenRow->chain_started_at
        );

        // 6 أيام أخرى من الـ rotations → السلسلة تتجاوز الـ 30 يوماً وتُرفض
        DB::table('personal_access_tokens')
            ->where('id', $newTokenRow->id)
            ->update(['chain_started_at' => now()->subDays(31)->format('Y-m-d H:i:s')]);

        // محاكاة طلب إنتاج جديد (الـ RequestGuard داخل نفس عملية الاختبار يخزّن
        // المستخدم المؤقت من الطلب السابق عبر الـ container)
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->postJson('/api/refresh-token')
            ->assertStatus(401);
    }

    public function test_fresh_token_rotates_and_old_token_is_deleted(): void
    {
        $user = User::factory()->patient()->create();
        $oldToken = $user->createToken('auth_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$oldToken->plainTextToken)
            ->postJson('/api/refresh-token');

        $response->assertOk();

        $newPlain = $response->json('token');
        $this->assertNotEmpty($newPlain);
        $this->assertNotSame($oldToken->plainTextToken, $newPlain);

        // الدوران: التوكن القديم حُذف ولم يتبقَّ سوى توكن واحد صالح
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldToken->accessToken->id,
        ]);
        $this->assertSame(1, $user->tokens()->count());

        // التوكن الجديد يعمل فعلياً
        $this->withHeader('Authorization', 'Bearer '.$newPlain)
            ->getJson('/api/profile/patient')
            ->assertOk();
    }
}
