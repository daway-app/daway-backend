<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * صيانة الكتالوج عبر الـAPI — العمليتان القابلتان للتقسيم.
 *
 * ⚠️ **لا يُشغَّل أي أمر حقيقي في هذه الاختبارات.** كل اختبار يستبدل
 * واجهة `Artisan` بمُزيّف يكتب «حالة» اصطناعية ويعيد رمز خروج، فلا يُقرأ ملف
 * الكتالوج (7.4MB/14MB) ولا يُكتب أي صف في قاعدة البيانات.
 *
 * يحرس هذا الملف العقود القابلة للكسر الصامت:
 *
 *   1. **القائمة البيضاء فقط.** لا تُقبل سلسلة أمر حرّة ⇒ لا `moh:import`
 *      ولا `moh:sync` ولا `--fresh` ولا حقن أوامر.
 *   2. **هوية الجولة والاستئناف.** عميل قديم/مكرَّر لا يستطيع الكتابة فوق
 *      تقدّم جولة جارية (`offset` المثبَّت هو المرجع لا ما يرسله العميل).
 *   3. **منع التكرار بالقفل الفعلي** (`Cache::lock`) لا بوعد نظري.
 *   4. **الأرقام صادقة.** لا تُختلق قيم `processed`/`total` عند غياب الحالة.
 */
class CatalogMaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/maintenance/catalog/run';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * مُزيّف واجهة Artisan: يكتب حالة اصطناعية ويعيد رمز خروج — بلا تنفيذ حقيقي.
     *
     * يعيد `ArrayObject` (لا مصفوفة) لأن الإغلاق يلتقطه بالمرجع؛ إعادة مصفوفة
     * كانت ستُعيد نسخة فارغة قبل أن يعمل الإغلاق أصلًا.
     *
     * @param  array<string,int>  $state
     */
    private function fakeArtisan(array $state, int $exitCode = 0): \ArrayObject
    {
        $calls = new \ArrayObject;

        Artisan::shouldReceive('call')->andReturnUsing(
            function (string $command, array $options = []) use ($calls, $state, $exitCode) {
                $calls->append(['command' => $command, 'options' => $options]);

                $key = $options['--state'] ?? null;
                if (is_string($key) && $key !== '') {
                    Cache::put($key, $state, now()->addHours(6));
                }

                return $exitCode;
            }
        );

        Artisan::shouldReceive('output')->andReturn('fake output');

        return $calls;
    }

    /**
     * مُزيّف متسلسل: كل نداء يأخذ حالته ورمز خروجه من `$steps` بالترتيب.
     * الحالة الفارغة `[]` تعني «الأمر لم يكتب أي تقدّم» (فشل مبكر).
     *
     * @param  array<int,array{0:array<string,int>,1:int}>  $steps
     */
    private function fakeArtisanSteps(array $steps): \ArrayObject
    {
        $calls = new \ArrayObject;
        $index = 0;

        Artisan::shouldReceive('call')->andReturnUsing(
            function (string $command, array $options = []) use ($calls, $steps, &$index) {
                $calls->append(['command' => $command, 'options' => $options]);

                [$state, $exitCode] = $steps[min($index, count($steps) - 1)];
                $index++;

                $key = $options['--state'] ?? null;
                if (is_string($key) && $key !== '' && $state !== []) {
                    Cache::put($key, $state, now()->addHours(6));
                }

                return $exitCode;
            }
        );

        Artisan::shouldReceive('output')->andReturn('fake output');

        return $calls;
    }

    // ══════════════════════════════════════════════════════════════════
    // المسار السعيد
    // ══════════════════════════════════════════════════════════════════

    public function test_admin_can_run_a_catalog_chunk(): void
    {
        $calls = $this->fakeArtisan(['offset' => 1500, 'total' => 3000, 'processed' => 1500]);

        $response = $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.operation', 'sync_categories')
            ->assertJsonPath('data.command', 'moh:sync-categories')
            ->assertJsonPath('data.offset', 1500)
            ->assertJsonPath('data.total', 3000)
            ->assertJsonPath('data.processed', 1500)
            ->assertJsonPath('data.done', false)
            ->assertJsonPath('data.limit', 1500)
            ->assertJsonPath('data.exit_code', 0);

        $this->assertNotEmpty($response->json('data.run_id'), 'يجب إعادة run_id.');

        // الأمر الفعلي هو الوحيد المُنفَّذ، وبالخيارات الثلاثة فقط.
        $this->assertCount(1, $calls);
        $this->assertSame('moh:sync-categories', $calls[0]['command']);
        $this->assertSame(0, $calls[0]['options']['--offset']);
        $this->assertSame(1500, $calls[0]['options']['--limit']);
        $this->assertArrayNotHasKey('--fresh', $calls[0]['options']);
    }

    public function test_classify_subcategories_passes_only_subcategories_only(): void
    {
        $calls = $this->fakeArtisan(['offset' => 1500, 'total' => 17295, 'processed' => 1500, 'sub_linked' => 10]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'classify_subcategories'])
            ->assertOk()
            ->assertJsonPath('data.command', 'classify:moh-catalog');

        $this->assertSame('classify:moh-catalog', $calls[0]['command']);
        $this->assertTrue($calls[0]['options']['--subcategories-only']);
        $this->assertArrayNotHasKey('--fresh', $calls[0]['options']);
    }

    public function test_run_reports_done_when_offset_reaches_total(): void
    {
        $this->fakeArtisan(['offset' => 3000, 'total' => 3000, 'processed' => 3000]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->assertJsonPath('data.done', true);

        // الجولة انتهت ⇒ لا جولة جارية تمنع بدء واحدة جديدة
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk();
    }

    /** لا نختلق أرقامًا عند غياب الحالة. */
    public function test_missing_state_reports_no_fabricated_progress(): void
    {
        Artisan::shouldReceive('call')->once()->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.processed', null)
            ->assertJsonPath('data.done', false);
    }

    // ══════════════════════════════════════════════════════════════════
    // هوية الجولة والاستئناف
    // ══════════════════════════════════════════════════════════════════

    public function test_resume_with_the_same_run_id_continues_from_the_stored_offset(): void
    {
        $calls = $this->fakeArtisan(['offset' => 1500, 'total' => 3000, 'processed' => 1500]);
        $admin = $this->userWithRole('admin');

        $runId = $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->json('data.run_id');

        // الشريحة الثانية: تُستأنف من 1500 الذي كتبه الأمر — لا من الصفر
        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories', 'run_id' => $runId])
            ->assertOk();

        $this->assertSame(1500, $calls[1]['options']['--offset']);
    }

    public function test_unknown_run_id_is_rejected(): void
    {
        $this->fakeArtisan(['offset' => 1500, 'total' => 3000]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, [
                'operation' => 'sync_categories',
                'run_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_a_second_run_without_run_id_is_rejected_while_one_is_in_progress(): void
    {
        $this->fakeArtisan(['offset' => 1500, 'total' => 3000, 'processed' => 1500]);
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk();

        // عميل آخر (أو نقر مزدوج) بلا run_id ⇒ لا يبدأ جولة موازية
        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertStatus(409);
    }

    public function test_mismatched_offset_is_rejected(): void
    {
        $this->fakeArtisan(['offset' => 1500, 'total' => 3000, 'processed' => 1500]);
        $admin = $this->userWithRole('admin');

        $runId = $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->json('data.run_id');

        // عميل قديم يحمل offset خاطئًا لا يكتب فوق التقدّم المثبَّت
        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, [
                'operation' => 'sync_categories',
                'run_id' => $runId,
                'offset' => 999,
            ])
            ->assertStatus(409)
            ->assertJsonPath('data.offset', 1500);
    }

    // ══════════════════════════════════════════════════════════════════
    // القائمة البيضاء والتحقق
    // ══════════════════════════════════════════════════════════════════

    public function test_unknown_operation_is_rejected(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'drop_everything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operation');
    }

    /**
     * 🔴 المستبعَدات صراحةً: `moh:import` و`moh:sync` وسلسلة الأمر الحرّة.
     * أي منها يجب ألا يصل إلى Artisan إطلاقًا.
     */
    public function test_excluded_commands_cannot_be_invoked(): void
    {
        Artisan::shouldReceive('call')->never();

        $admin = $this->userWithRole('admin');

        foreach ([
            ['operation' => 'moh:import'],
            ['operation' => 'moh_import'],
            ['operation' => 'moh:sync'],
            ['operation' => 'moh_sync'],
            ['operation' => 'fresh'],
        ] as $payload) {
            $this->actingAs($admin, 'sanctum')
                ->postJson(self::ENDPOINT, $payload)
                ->assertStatus(422);
        }

        // بلا عملية أصلاً
        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['command' => 'moh:import'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operation');
    }

    public function test_arbitrary_command_field_is_never_forwarded_to_artisan(): void
    {
        $calls = $this->fakeArtisan(['offset' => 1, 'total' => 10, 'processed' => 1]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, [
                'operation' => 'sync_categories',
                'command' => 'moh:import',
                'fresh' => true,
                'options' => ['--fresh' => true],
            ])
            ->assertOk();

        $this->assertSame('moh:sync-categories', $calls[0]['command']);
        $this->assertArrayNotHasKey('--fresh', $calls[0]['options']);
        $this->assertArrayNotHasKey('--file', $calls[0]['options']);
    }

    public function test_invalid_offset_and_limit_are_rejected(): void
    {
        Artisan::shouldReceive('call')->never();

        $admin = $this->userWithRole('admin');

        foreach ([
            ['operation' => 'sync_categories', 'offset' => -1],
            ['operation' => 'sync_categories', 'limit' => 1501],
            ['operation' => 'sync_categories', 'limit' => 0],
            ['operation' => 'sync_categories', 'offset' => 'abc'],
            ['operation' => 'sync_categories', 'run_id' => 'bad run id!'],
        ] as $payload) {
            $this->actingAs($admin, 'sanctum')
                ->postJson(self::ENDPOINT, $payload)
                ->assertStatus(422);
        }
    }

    public function test_limit_is_capped_at_1500(): void
    {
        $calls = $this->fakeArtisan(['offset' => 1500, 'total' => 3000, 'processed' => 1500]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories', 'limit' => 1500])
            ->assertOk();

        $this->assertSame(1500, $calls[0]['options']['--limit']);
    }

    // ══════════════════════════════════════════════════════════════════
    // منع التكرار (القفل الفعلي)
    // ══════════════════════════════════════════════════════════════════

    public function test_a_held_lock_rejects_a_concurrent_chunk(): void
    {
        Artisan::shouldReceive('call')->never();

        $lock = Cache::lock('catalog-maintenance-lock', 300);
        $this->assertTrue($lock->get(), 'التهيئة: يجب حجز القفل.');

        try {
            $this->actingAs($this->userWithRole('admin'), 'sanctum')
                ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
                ->assertStatus(409)
                ->assertJsonPath('success', false);
        } finally {
            $lock->release();
        }
    }

    public function test_the_lock_is_released_after_a_chunk(): void
    {
        $this->fakeArtisan(['offset' => 1, 'total' => 10, 'processed' => 1]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk();

        // لو لم يُحرَّر القفل لظلّ محجوزًا حتى انتهاء مدته (300ث)
        $probe = Cache::lock('catalog-maintenance-lock', 300);
        $this->assertTrue($probe->get(), 'القفل يجب أن يُحرَّر بعد الشريحة.');
        $probe->release();
    }

    /** مسار ملف قفل التنفيذ المشترك (نفس ما يستخدمه الكنترولر). */
    private function executionLockPath(): string
    {
        return storage_path('framework/cache/catalog-maintenance.lock');
    }

    /**
     * D-3: الحماية الفعلية من التنفيذ المتوازي هي `flock` المحتجز **طوال
     * الشريحة** — يعمل حتى لو انتهى قفل الكاش (TTL) أثناء تنفيذ طويل.
     * هنا لا يوجد قفل كاش محتجز إطلاقًا، ومع ذلك يُرفض الطلب.
     */
    public function test_the_execution_lock_blocks_even_when_no_cache_lock_is_held(): void
    {
        Artisan::shouldReceive('call')->never();

        @mkdir(dirname($this->executionLockPath()), 0o775, true);
        $handle = fopen($this->executionLockPath(), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'التهيئة: حجز قفل التنفيذ المحلي.');

        try {
            $this->actingAs($this->userWithRole('admin'), 'sanctum')
                ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
                ->assertStatus(409)
                // الرسالة تثبت أن الرفض جاء من قفل التنفيذ لا من قفل الكاش
                ->assertJsonPath('message', 'عملية صيانة أخرى قيد التنفيذ فعليًا — أعد المحاولة بعد قليل.');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_the_execution_lock_is_released_after_a_chunk(): void
    {
        $this->fakeArtisan(['offset' => 1, 'total' => 10, 'processed' => 1]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk();

        $handle = fopen($this->executionLockPath(), 'c');

        $this->assertTrue(
            flock($handle, LOCK_EX | LOCK_NB),
            'قفل التنفيذ يجب أن يُحرَّر بعد انتهاء الشريحة.'
        );

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** H-1: الاستثناء يمرّ بـ`finally` فيُغلق المقبض دائمًا. */
    public function test_the_execution_lock_is_released_when_the_command_throws(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new \RuntimeException('boom'));
        Artisan::shouldReceive('output')->andReturn('');

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertStatus(500);

        $handle = fopen($this->executionLockPath(), 'c');

        $this->assertTrue(
            flock($handle, LOCK_EX | LOCK_NB),
            'القفل يجب أن يُحرَّر حتى عند رمي استثناء من الأمر.'
        );

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * H-3: هوية القفل **مشتركة** بين العمليتين — ما دام أحدهما يعمل يُرفض الآخر،
     * لأنهما يكتبان نفس الجداول (`category_medicine_links`) ويُبطلان نفس الكاش.
     */
    public function test_both_operations_share_one_execution_lock(): void
    {
        Artisan::shouldReceive('call')->never();

        @mkdir(dirname($this->executionLockPath()), 0o775, true);
        $handle = fopen($this->executionLockPath(), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'التهيئة: حجز القفل المشترك.');

        try {
            foreach (['sync_categories', 'classify_subcategories'] as $operation) {
                $this->actingAs($this->userWithRole('admin'), 'sanctum')
                    ->postJson(self::ENDPOINT, ['operation' => $operation])
                    ->assertStatus(409);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * الدليل الأقوى: العملية الأخرى تُرفض **أثناء تنفيذ** الأولى فعليًا
     * (لا بمحاكاة قفل محتجز يدويًا).
     */
    public function test_one_operation_blocks_the_other_while_the_command_runs(): void
    {
        $otherStatus = null;

        Artisan::shouldReceive('call')->once()->andReturnUsing(function () use (&$otherStatus) {
            // نحن داخل شريحة sync_categories والقفل محتجز
            $otherStatus = $this->postJson(self::ENDPOINT, ['operation' => 'classify_subcategories'])->status();

            return 0;
        });
        Artisan::shouldReceive('output')->andReturn('');

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk();

        $this->assertSame(
            409,
            $otherStatus,
            'العملية الأخرى يجب أن تُرفض أثناء احتلال القفل المشترك.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // الفشل
    // ══════════════════════════════════════════════════════════════════

    public function test_a_failing_command_keeps_the_run_when_progress_exists(): void
    {
        // شريحة 1 تنجح وتكتب تقدّمًا، شريحة 2 تفشل بلا تقدّم جديد.
        $this->fakeArtisanSteps([
            [['offset' => 1500, 'total' => 3000, 'processed' => 1500], 0],
            [[], 1],
        ]);

        $admin = $this->userWithRole('admin');

        $runId = $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->json('data.run_id');

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories', 'run_id' => $runId])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.exit_code', 1)
            ->assertJsonPath('data.done', false)
            // التقدّم المحفوظ لا يُفقد
            ->assertJsonPath('data.offset', 1500)
            ->assertJsonPath('data.total', 3000);
    }

    /**
     * 🔴 D-1: فشل **الشريحة الأولى** بلا أي تقدّم محفوظ يجب ألا يترك
     * «جولة جارية» معلّقة تمنع المحاولة التالية.
     */
    public function test_a_first_chunk_failure_allows_a_fresh_attempt_without_a_recovery_request(): void
    {
        $this->fakeArtisanSteps([
            [[], 1],                                                     // الشريحة الأولى: فشل بلا حالة
            [['offset' => 1500, 'total' => 3000, 'processed' => 1500], 0],
        ]);

        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        // محاولة جديدة **بلا run_id** تنجح فورًا — لا 409 ولا طلب استرجاع إضافي
        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offset', 1500);
    }

    /** D-2: لا نُبلّغ صفرًا مختلقًا حين لا يكتب الأمر عدّاد `processed`. */
    public function test_processed_is_null_when_the_command_does_not_persist_it(): void
    {
        $this->fakeArtisan(['offset' => 1, 'total' => 10]);

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertOk()
            ->assertJsonPath('data.processed', null)
            ->assertJsonPath('data.offset', 1)
            ->assertJsonPath('data.total', 10);
    }

    // ══════════════════════════════════════════════════════════════════
    // التفويض + عدم تأثّر المسارات الأخرى
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_run_catalog_maintenance(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
            ->assertStatus(401);
    }

    public function test_non_admin_cannot_run_catalog_maintenance(): void
    {
        Artisan::shouldReceive('call')->never();

        foreach (['pharmacy', 'patient'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->postJson(self::ENDPOINT, ['operation' => 'sync_categories'])
                ->assertStatus(403);
        }
    }

    public function test_the_existing_dry_run_endpoint_is_unaffected(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_public_category_endpoints_are_unaffected(): void
    {
        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/dosage-forms')->assertOk();
    }
}
