<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تكافؤ الإشعارات بين الويب والـAPI — M-22.
 *
 * قبل هذه المرحلة كان توليد إشعارات النظام (نقص المخزون) خاصاً بواجهة
 * Blade (`web\Admin\NotificationController`)، والـAPI كان يقرأ فقط. الآن
 * `Api\NotificationController::{index,count}` يستدعيان
 * `NotificationGenerator::syncThrottledFor()` — **نفس** نقطة التكامل
 * وبنفس الخنق (مرة/دقيقة/مستخدم).
 *
 * آمن ضد التكرار: `syncForUser` يتخطّى أي دواء له إشعار `low_stock` أو
 * `out_of_stock` قائم للمستخدم **بغضّ النظر عن مقروئيته** (M-2.4 — الخيار
 * A: التفريد عبر النوعين) ⇒ لا صفوف مكررة.
 *
 * ✅ تصنيف الحالة أصبح **مطابقًا** لمسار `LowStockNotifier` الحدثي:
 * `quantity <= 0` ⇒ `out_of_stock` · `0 < quantity <= الحد` ⇒ `low_stock`.
 *
 * ⚠️ الأثر المقصود والمُوثَّق: المولّد (الاستطلاع) لا يُعيد الإشعار عند
 * تحوّل النوع بين النفاد والنقص — **المسار الحدثي** (`LowStockNotifier`)
 * هو مصدر التحولات، وسلوكه الموثّق في `LowStockNotificationDedupTest`
 * يبقى كما هو (مُختبَر أدناه).
 *
 * لا يفعل شيئاً للمريض: بلا صيدلية وليس أدمن ⇒ no-op.
 *
 */
class NotificationParityTest extends TestCase
{
    use RefreshDatabase;

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

    /** صيدلية واحدة لكل مستخدم (pharmacies.user_id فريد). */
    private function pharmacyFor(User $pharmacyUser): Pharmacy
    {
        $existing = Pharmacy::where('user_id', $pharmacyUser->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
    }

    private function addLowStock(Pharmacy $pharmacy, string $name, int $quantity): PharmacyMedicine
    {
        $medicine = Medicine::create([
            'trade_name' => $name,
            'active_ingredient' => 'باراسيتامول',
        ]);

        return PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => $quantity,
            'is_available' => $quantity > 0,
        ]);
    }

    private function notificationsFor(User $user): int
    {
        return Notification::where('user_id', $user->id)->count();
    }

    public function test_pharmacy_user_polling_generates_a_low_stock_notification(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $pm = $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'medicine_id' => $pm->medicine_id,
            'type' => 'low_stock',
        ]);
    }

    /** الخنق: الاستدعاء الثاني داخل نافذة 60ث لا يُولّد شيئاً. */
    public function test_throttle_defers_regeneration_within_the_window(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        // دواء ثانٍ منخفض المخزون **بعد** التشغيل الأول
        $second = $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Brufen', 3);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(
            1,
            $this->notificationsFor($pharmacyUser),
            'داخل نافذة الخنق يجب ألا يُولَّد شيء جديد.'
        );

        // تجاوز الخنق ⇒ التوليد يعمل وينشئ الدواء الثاني فقط
        Cache::forget('notif_sync_at_'.$pharmacyUser->id);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(2, $this->notificationsFor($pharmacyUser));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'medicine_id' => $second->medicine_id,
            'type' => 'low_stock',
        ]);
    }

    /**
     * 🔴 دليل عدم التكرار المستقل عن الخنق: `syncForUser` يتخطّى دواءً له
     * إشعار قائم (مقروءاً كان أم لا) ⇒ لا صفوف مكررة.
     */
    public function test_the_generator_does_not_duplicate_when_the_throttle_is_cleared(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(1, $this->notificationsFor($pharmacyUser));

        // جعل الإشعار مقروءاً: هذا يُغيّر سلوك LowStockNotifier (C7) لكن
        // المولّد يتخطّاه رغم ذلك — هوية تفريد أوسع.
        Notification::where('user_id', $pharmacyUser->id)->update(['is_read' => true]);

        Cache::forget('notif_sync_at_'.$pharmacyUser->id);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(
            1,
            $this->notificationsFor($pharmacyUser),
            'المولّد يجب ألا يكرر إشعاراً قائماً حتى لو صار مقروءاً.'
        );
    }

    /** المريض: بلا صيدلية وليس أدمن ⇒ no-op (بلا أي كتابة). */
    public function test_patient_polling_is_a_noop(): void
    {
        $patient = $this->userWithRole('patient');
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($patient, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $this->notificationsFor($patient));
    }

    public function test_admin_polling_generates_global_low_stock_notifications(): void
    {
        $admin = $this->userWithRole('admin');
        $pharmacyUser = $this->userWithRole('pharmacy');
        $pm = $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'medicine_id' => $pm->medicine_id,
            'type' => 'low_stock',
        ]);
    }

    public function test_index_returns_the_generated_notification(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.type', 'low_stock')
            ->assertJsonPath('unread_count', 1);
    }

    /**
     * ⚠️ سلوك موجود يُثبَّت عمداً: المولّد يُنشئ `low_stock` حتى عند الكمية
     * صفر، بينما المسار الحدثي (`LowStockNotifier`) ينشئ `out_of_stock`.
     * انحراف دلالي بين المسارين — موثَّق في docblock الكلاس، خارج نطاق هذه
     * المرحلة إصلاحه.
     */
    /**
     * M-2.4: النفاد الكامل (quantity = 0) يُصنَّف **`out_of_stock`** —
     * نفس تصنيف `LowStockNotifier`، وليس `low_stock` كما كان.
     * لا إشعار سابق ⇒ يُنشأ لأول مرة.
     */
    public function test_zero_quantity_is_reported_as_out_of_stock_by_the_poll_path(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 0);

        // لا إشعار سابق لهذا الدواء
        $this->assertDatabaseCount('notifications', 0);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'out_of_stock',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'low_stock',
        ]);
    }

    public function test_positive_low_quantity_is_reported_as_low_stock(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 5);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'low_stock',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'out_of_stock',
        ]);
    }

    /** M-2.4: التفريد عبر النوعين ⇒ إعادة تشغيل المولّد لا تكرّر `out_of_stock`. */
    public function test_out_of_stock_is_not_duplicated_after_regeneration(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $this->addLowStock($this->pharmacyFor($pharmacyUser), 'Panadol', 0);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(1, $this->notificationsFor($pharmacyUser));

        // تجاوز الخنق ⇒ المولّد يعمل مرة أخرى ⇒ لا تكرار (نفس النوع أو غيره)
        Cache::forget('notif_sync_at_'.$pharmacyUser->id);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(
            1,
            $this->notificationsFor($pharmacyUser),
            'التفريد عبر النوعين يجب أن يمنع تكرار إشعار النفاد.'
        );

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'low_stock',
        ]);
    }

    /**
     * 🔴 سلوك تغيّر الحالة الموثّق في `LowStockNotificationDedupTest` **يبقى
     * كما هو**: المسار الحدثي (`LowStockNotifier` عبر تحديث المخزون) ينشئ
     * إشعارًا جديدًا عند تحوّل النوع — حتى بعد أن سبق المولّد بتشغيل.
     * المولّد لا يمنع المسار الحدثي ولا يكرّر ما أنشأه.
     */
    public function test_the_event_path_still_transitions_after_the_generator_has_run(): void
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $pharmacy = $this->pharmacyFor($pharmacyUser);
        $pm = $this->addLowStock($pharmacy, 'Panadol', 0);

        // المولّد يُنشئ `out_of_stock` (نفاد كامل)
        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'out_of_stock',
        ]);

        // المخزون يرتفع إلى نطاق low-stock عبر **المسار الحدثي**
        $this->actingAs($pharmacyUser, 'sanctum')
            ->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 5])
            ->assertOk();

        // C7: تحوّل النوع ⇒ إشعار جديد منفصل (`low_stock`) بجانب `out_of_stock`
        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'medicine_id' => $pm->medicine_id,
            'type' => 'low_stock',
        ]);

        $this->assertSame(2, $this->notificationsFor($pharmacyUser));

        // المولّد لا يضيف شيئًا بعده (التفريد عبر النوعين)
        Cache::forget('notif_sync_at_'.$pharmacyUser->id);

        $this->actingAs($pharmacyUser, 'sanctum')
            ->getJson('/api/notifications/count')
            ->assertOk();

        $this->assertSame(2, $this->notificationsFor($pharmacyUser));
    }
}
