<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Favorite;
use App\Models\Medicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لوحة الأدمن API — CRUD كتالوج الأدوية (show / update / destroy).
 *
 * الخلفية: الخلفية كانت تعرض `POST /api/admin/medicines` فقط. شاشات
 * الإضافة/التعديل في React تحتاج ثلاثة مسارات إضافية، وتفاصيل الدواء تحتاج
 * قراءة مفردة. أُضيفت هنا بنقل منطق `web\Admin\MedicineController` نفسه.
 *
 * يحرس هذا الاختبار أربعة عقود قابلة للكسر الصامت:
 *
 *   1. **الحذف يجب أن ينظّف `favorites`.** العلاقة polymorphic ⇒ لا يوجد
 *      FK يُسلسل الحذف. لو نُسي التنظيف، بقي صف يتيم يجعل مفضّلة المريض
 *      تُرجع دواءً محذوفًا (خطأ 500 أو صف فارغ في الواجهة).
 *
 *   2. **`alternatives` الغائبة على `update` تعني «افرغ القائمة»** — نفس سلوك
 *      الـweb controller حرفيًا. لو صار التعامل مختلفًا، لتباعدت الشاشتان
 *      بلا أن يلاحظ أحد (TomSelect يرسل قائمة فارغة عند المسح).
 *
 *   3. **`show` يعيد `alternative_ids`** لا `alternatives` فقط — الشاشة
 *      تحتاج قيمًا مُهيّأة للمُحدِّد بلا استخراج على العميل.
 *
 *   4. **المسار الخام `image` لا يتسرّب**؛ `image_url` فقط، مثل مسار القائمة.
 */
class AdminMedicineCrudApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $user->role = 'admin';
        $user->is_active = true;
        $user->save();
        $user->syncRoles(['admin']);

        return $user;
    }

    private function medicine(string $name = 'دواء اختبار'): Medicine
    {
        return Medicine::create([
            'trade_name' => $name,
            'active_ingredient' => 'باراسيتامول',
            'description' => 'وصف',
            'image' => null,
        ]);
    }

    /** الحارس الأهم: الحذف يجب أن يزيل صفوف favorites المرتبطة. */
    public function test_destroy_removes_polymorphic_favorites(): void
    {
        $medicine = $this->medicine();

        // صف مفضّلة يتيم بلا FK — يُكتب مباشرة لأن العلاقة polymorphic.
        Favorite::create([
            'user_id' => $this->admin()->id,
            'favoritable_type' => Medicine::class,
            'favoritable_id' => $medicine->id,
        ]);

        $this->assertSame(
            1,
            Favorite::where('favoritable_type', Medicine::class)
                ->where('favoritable_id', $medicine->id)
                ->count(),
            'التهيئة: يجب أن يوجد صف مفضّلة واحد قبل الحذف.',
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/medicines/{$medicine->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('medicines', ['id' => $medicine->id]);

        $this->assertSame(
            0,
            Favorite::where('favoritable_type', Medicine::class)
                ->where('favoritable_id', $medicine->id)
                ->count(),
            '🔴 صف مفضّلة يتيم: بدون التنظيف اليدوي يبقى صف يشير إلى دواء محذوف.',
        );
    }

    /** `alternatives` الغائبة = افرغ القائمة، مطابقةً للـweb controller. */
    public function test_update_without_alternatives_key_detaches_all(): void
    {
        $medicine = $this->medicine('الأصل');
        $alt = $this->medicine('البديل');
        $medicine->alternatives()->sync([$alt->id]);

        $this->assertSame(1, $medicine->fresh()->alternatives()->count(), 'التهيئة: بديل واحد مرتبط.');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/medicines/{$medicine->id}", [
                'name_ar' => 'الأصل بعد التعديل',
                'active_ingredient' => 'أموكسيسيلين',
                // لا مفتاح alternatives إطلاقًا — هذا هو المقصود.
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $medicine->fresh();
        $this->assertSame('الأصل بعد التعديل', $fresh->trade_name);
        $this->assertSame('أموكسيسيلين', $fresh->active_ingredient);

        $this->assertSame(
            0,
            $fresh->alternatives()->count(),
            '🔴 غياب alternatives يجب أن يُفرغ القائمة، وإلا تباعدت شاشة React عن شاشة Blade.',
        );
    }

    /** الإرسال الصريح لقائمة فارغة يفعل الشيء نفسه — وهو ما تفعله شاشة React. */
    public function test_update_with_an_explicit_empty_alternatives_array_detaches_all(): void
    {
        $medicine = $this->medicine('الأصل');
        $alt = $this->medicine('البديل');
        $medicine->alternatives()->sync([$alt->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/medicines/{$medicine->id}", [
                'name_ar' => 'الأصل',
                'active_ingredient' => 'باراسيتامول',
                'alternatives' => [],
            ])
            ->assertOk();

        $this->assertSame(0, $medicine->fresh()->alternatives()->count());
    }

    public function test_update_syncs_the_submitted_alternatives(): void
    {
        $medicine = $this->medicine('الأصل');
        $a = $this->medicine('بديل أ');
        $b = $this->medicine('بديل ب');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/medicines/{$medicine->id}", [
                'name_ar' => 'الأصل',
                'active_ingredient' => 'باراسيتامول',
                'alternatives' => [$a->id, $b->id],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $medicine->fresh()->alternatives()->pluck('medicines.id')->all(),
        );
    }

    /** `show` يعيد `alternative_ids` جاهزة للمُحدِّد، و`image_url` لا مسارًا خامًا. */
    public function test_show_returns_alternative_ids_and_a_resolved_image_url(): void
    {
        $medicine = $this->medicine('دواء بصورة');
        $medicine->update(['image' => 'medicines/sample.jpg']);
        $alt = $this->medicine('البديل');
        $medicine->alternatives()->sync([$alt->id]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/medicines/{$medicine->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $medicine->id)
            ->assertJsonPath('data.alternative_ids', [$alt->id]);

        $this->assertArrayNotHasKey('image', $response->json('data'), 'المسار الخام يجب ألا يتسرّب.');
        $this->assertMatchesRegularExpression(
            '#^https?://#',
            (string) $response->json('data.image_url'),
            'الصورة يجب أن تُعاد كرابط مطلق.',
        );
    }

    /** الحقول المطلوبة تُرفض بـ422 — لا إنشاء صف فارغ. */
    public function test_store_rejects_a_missing_name(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/medicines', [
                'active_ingredient' => 'باراسيتامول',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);
    }

    /** بديل غير موجود ⇒ 422، لا صف ربط يشير إلى لا شيء. */
    public function test_update_rejects_a_nonexistent_alternative_id(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/medicines/{$medicine->id}", [
                'name_ar' => 'الأصل',
                'active_ingredient' => 'باراسيتامول',
                'alternatives' => [999999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alternatives.0']);
    }

    /** غير الأدمن يُرفض على كل المسارات الثلاثة الجديدة. */
    public function test_non_admin_cannot_use_the_new_routes(): void
    {
        $medicine = $this->medicine();

        $pharmacy = User::create([
            'name' => 'صيدلية',
            'email' => 'ph-'.uniqid().'@test.com',
            'phone' => '0598'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $pharmacy->role = 'pharmacy';
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->syncRoles(['pharmacy']);

        $this->actingAs($pharmacy, 'sanctum')
            ->getJson("/api/admin/medicines/{$medicine->id}")
            ->assertStatus(403);

        $this->actingAs($pharmacy, 'sanctum')
            ->putJson("/api/admin/medicines/{$medicine->id}", [
                'name_ar' => 'x',
                'active_ingredient' => 'y',
            ])
            ->assertStatus(403);

        $this->actingAs($pharmacy, 'sanctum')
            ->deleteJson("/api/admin/medicines/{$medicine->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('medicines', ['id' => $medicine->id]);
    }
}
