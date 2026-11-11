<?php

namespace Tests\Feature\Api\Fcm;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\DeferredFcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عدّاد بسيط: يسجّل كل استدعاء إرسال فعلي.
 */
class CountingFcmSender implements FcmSender
{
    /** @var list<int> */
    public array $sent = [];

    public function enabled(): bool
    {
        return true;
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $this->sent[] = (int) ($data['notification_id'] ?? 0);
    }

    public function fromNotification(Notification $notification): void
    {
        $this->sent[] = $notification->id;
    }
}

/**
 * إثبات سلوك `DB::afterCommit` على قاعدة حقيقية.
 *
 * لا يستخدم `RefreshDatabase` عمدًا: ذاك يلفّ كل اختبار في معاملة لا تُثبَّت
 * أبدًا، فلا ينفّذ `afterCommit` شيئًا ويصبح الاختبار أعمى. هنا تُدار الهجرات
 * لكل اختبار (`DatabaseMigrations`)، فمعاملاتنا هي المعاملات الحقيقية.
 *
 * المزيّن يُبنى يدويًا بـ`defer: true` — نفس ما يفعله مزوّد الخدمة في الإنتاج.
 */
class FcmAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * بلا معاملة محيطة: `RefreshDatabase` يلفّ كل اختبار في معاملة تُلغى في
     * النهاية، فلا ينفّذ `afterCommit` أبدًا. تعطيل المعاملة هنا يجعل معاملات
     * الاختبار هي المعاملات الحقيقية، وتبقى الهجرات مُدارة تلقائيًا.
     *
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        // قاعدة `:memory:` تُنشأ من جديد لكل اختبار (اتصال جديد)، بينما
        // `RefreshDatabase` يهاجر مرة واحدة لكل عملية. إعادة تصفير العلَم
        // تجعله يهاجر من جديد، فتبقى الجداول موجودة قبل زرع الأدوار.
        RefreshDatabaseState::$migrated = false;

        parent::setUp();

        Notification::query()->delete();
    }

    private function notificationFor(User $user): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => 'order_status',
            'message' => 'تحديث على طلبك',
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    public function test_the_push_is_not_sent_before_the_transaction_commits(): void
    {
        $user = User::factory()->patient()->create();
        $spy = new CountingFcmSender;
        $sender = new DeferredFcmSender($spy, defer: true);

        DB::beginTransaction();
        $notification = $this->notificationFor($user);
        $sender->fromNotification($notification);

        $this->assertSame([], $spy->sent, 'لا يجوز إرسال push قبل تثبيت المعاملة');

        DB::commit();

        $this->assertSame([$notification->id], $spy->sent, 'بعد الـcommit يُرسل مرة واحدة');
    }

    public function test_a_rollback_prevents_the_push_entirely(): void
    {
        $user = User::factory()->patient()->create();
        $spy = new CountingFcmSender;
        $sender = new DeferredFcmSender($spy, defer: true);

        DB::beginTransaction();
        $this->notificationFor($user);
        $sender->fromNotification(Notification::firstOrFail());

        DB::rollBack();

        $this->assertSame([], $spy->sent, 'التراجع يعني ألا يظهر إشعار لبيانات لم تُحفظ');
        $this->assertSame(0, Notification::count());
    }

    public function test_commit_sends_exactly_once(): void
    {
        $user = User::factory()->patient()->create();
        $spy = new CountingFcmSender;
        $sender = new DeferredFcmSender($spy, defer: true);

        DB::beginTransaction();
        $notification = $this->notificationFor($user);
        $sender->fromNotification($notification);
        DB::commit();

        $this->assertCount(1, $spy->sent);
        $this->assertSame($notification->id, $spy->sent[0]);
    }

    public function test_without_a_transaction_the_push_is_sent_immediately(): void
    {
        $user = User::factory()->patient()->create();
        $spy = new CountingFcmSender;
        $sender = new DeferredFcmSender($spy, defer: true);

        $notification = $this->notificationFor($user);
        $sender->fromNotification($notification);

        // `DB::afterCommit` ينفّذ فورًا خارج أي معاملة.
        $this->assertSame([$notification->id], $spy->sent);
    }

    public function test_deferral_can_be_disabled_for_direct_sends(): void
    {
        $user = User::factory()->patient()->create();
        $spy = new CountingFcmSender;
        $sender = new DeferredFcmSender($spy, defer: false);

        DB::beginTransaction();
        $notification = $this->notificationFor($user);
        $sender->fromNotification($notification);

        $this->assertSame([$notification->id], $spy->sent, 'defer:false يرسل فورًا');

        DB::rollBack();
    }
}
