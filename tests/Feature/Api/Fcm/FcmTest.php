<?php

namespace Tests\Feature\Api\Fcm;

use App\Contracts\FcmSender;
use App\Models\DeviceToken;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Support\LowStockNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FcmTest extends TestCase
{
    use RefreshDatabase;

    private $senderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Fake sender: يلتقط كل استدعاء بدل إرسال Firebase فعلي
        $this->senderCalls = [];
        $fake = new class ($this->senderCalls) implements FcmSender {
            public function __construct(private array &$calls) {}

            public function enabled(): bool
            {
                return true;
            }

            public function sendToUser(User $user, string $title, string $body, array $data = []): void
            {
                $this->calls[] = [
                    'user_id' => $user->id,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ];
            }

            public function fromNotification(Notification $notification): void
            {
                $this->calls[] = [
                    'user_id' => $notification->user_id,
                    'title' => $notification->type,
                    'body' => (string) $notification->message,
                    'data' => [
                        'notification_id' => $notification->id,
                        'type' => $notification->type,
                    ],
                ];
            }
        };
        $this->app->singleton(FcmSender::class, fn () => $fake);
    }

    private function pharmacyUser(): User
    {
        return User::factory()->pharmacy()->create();
    }

    private function pharmacyFor(User $user): Pharmacy
    {
        return Pharmacy::factory()->create(['user_id' => $user->id]);
    }

    public function test_pharmacy_can_register_web_token(): void
    {
        $user = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/device-tokens', [
            'token' => 'WEB_FCM_TOKEN_1',
            'platform' => 'web',
            'device_id' => 'stable-browser-uuid',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'WEB_FCM_TOKEN_1',
            'platform' => 'web',
            'device_id' => 'stable-browser-uuid',
        ]);
    }

    public function test_duplicate_token_for_another_user_is_rejected(): void
    {
        $a = $this->pharmacyUser();
        Sanctum::actingAs($a);
        $this->postJson('/api/device-tokens', [
            'token' => 'SHARED_TOKEN',
            'platform' => 'web',
            'device_id' => 'dev-a',
        ])->assertStatus(201);

        $b = User::factory()->patient()->create();
        Sanctum::actingAs($b);
        $this->postJson('/api/device-tokens', [
            'token' => 'SHARED_TOKEN',
            'platform' => 'android',
            'device_id' => 'dev-b',
        ])->assertStatus(422);
    }

    public function test_low_stock_pushes_once_for_new_notification(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create(['trade_name' => 'PushMed']);
        DeviceToken::create([
            'user_id' => $user->id,
            'token' => 'PHARMACY_FCM_TOKEN',
            'platform' => 'web',
            'device_id' => 'dev-1',
        ]);

        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 50,
            'is_available' => true,
        ]);

        // نقص المخزون (نفس نقطة الدخول الإنتاجية) → إشعار جديد → push مرة واحدة
        $pm->refresh();
        $pm->quantity = 5;
        LowStockNotifier::notifyIfLowStock($pm);

        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'low_stock')->count());
        $this->assertCount(1, $this->senderCalls);
        $this->assertSame($user->id, $this->senderCalls[0]['user_id']);
        $this->assertSame('low_stock', $this->senderCalls[0]['data']['type']);
    }

    public function test_duplicate_low_stock_does_not_push_again(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        DeviceToken::create([
            'user_id' => $user->id,
            'token' => 'T1',
            'platform' => 'android',
            'device_id' => 'd1',
        ]);

        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'is_available' => true,
        ]);

        // أول تعديل → إشعار جديد → push ×1
        $pm->refresh();
        $pm->quantity = 4;
        LowStockNotifier::notifyIfLowStock($pm);
        $this->assertCount(1, $this->senderCalls);

        // ثانية بنفس الحالة → dedup C7 يحدّث الإشعار القائم → لا push إضافي
        $pm->refresh();
        $pm->quantity = 3;
        LowStockNotifier::notifyIfLowStock($pm);
        $this->assertCount(1, $this->senderCalls);
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'low_stock')->count());
    }

    public function test_medicine_available_pushes_each_subscriber_once(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacyUser = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($pharmacyUser);
        $medicine = Medicine::factory()->create();
        DeviceToken::create([
            'user_id' => $patient->id,
            'token' => 'PATIENT_TOKEN',
            'platform' => 'android',
            'device_id' => 'pd1',
        ]);

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
        ])->assertStatus(201);

        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 0,
            'is_available' => false,
        ]);

        // الانتقال إلى متوفر → إشعار للمشترك → push ×1 للمريض
        $pm->update(['quantity' => 10, 'is_available' => true]);

        $this->assertSame(1, Notification::where('user_id', $patient->id)->where('type', 'medicine_available')->count());
        $this->assertCount(1, $this->senderCalls);
        $this->assertSame($patient->id, $this->senderCalls[0]['user_id']);
        $this->assertSame('medicine_available', $this->senderCalls[0]['data']['type']);
    }

    public function test_new_inquiry_pushes_pharmacy_user(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacyUser = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($pharmacyUser);
        $medicine = Medicine::factory()->create();
        DeviceToken::create([
            'user_id' => $pharmacyUser->id,
            'token' => 'PHARM_TOKEN',
            'platform' => 'web',
            'device_id' => 'pw1',
        ]);

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/inquiries', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'متوفر؟',
        ])->assertStatus(201);

        $this->assertSame(1, Notification::where('user_id', $pharmacyUser->id)->where('type', 'new_inquiry')->count());
        $this->assertCount(1, $this->senderCalls);
        $this->assertSame($pharmacyUser->id, $this->senderCalls[0]['user_id']);
        $this->assertSame('new_inquiry', $this->senderCalls[0]['data']['type']);
    }

    public function test_push_without_tokens_is_silent(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
        ]);

        // لا توكنات مسجلة → لا استثناء، الإشعار يُخزن عادي.
        // (فحص التوكنات داخل FcmPushService الحقيقي — الـ fake هنا يسجل الاستدعاء فقط)
        $pm->refresh();
        $pm->quantity = 3;
        LowStockNotifier::notifyIfLowStock($pm);

        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'low_stock')->count());
    }

    public function test_fcm_sender_binding_resolves(): void
    {
        $sender = app(FcmSender::class);
        $this->assertInstanceOf(FcmSender::class, $sender);
        $this->assertTrue($sender->enabled());
    }
}
