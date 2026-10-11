<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Medicine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لوحة الأدمن API — حقل صورة الدواء.
 *
 * الخلفية: `GET /api/admin/medicines` كان لا يختار `m.image` إطلاقاً، فالرد
 * لا يحمل أي إشارة إلى الصورة. لوحة React تحتاج الصورة لعرض مُصغّرة في جدول
 * الأدوية، فالحقل صار يُحلّ على الخادم عبر `Image::url` ويُعاد باسم `image_url`
 * (نفس نمط واجهة الصيدلية) — مع **إسقاط المسار الخام `image`** من الرد.
 *
 * هذا الاختبار يثبّت العقد:
 *   1. كل صف يحمل المفتاح `image_url` (حتى لو `null`؛ لا مفتاح مفقود).
 *   2. الصورة المضبوطة تظهر كرابط مطلق غير فارغ.
 *   3. المسار الخام `image` **لا** يتسرّب للعميل.
 *   4. المفتاح لا يكسر بقية الحقول (trade_name / stock ...).
 */
class AdminMedicineImageApiTest extends TestCase
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

    public function test_every_row_exposes_an_image_url_key(): void
    {
        Medicine::create([
            'trade_name' => 'دواء بلا صورة',
            'active_ingredient' => 'باراسيتامول',
            'image' => null,
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/medicines');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $medicines = $response->json('data.medicines');
        $this->assertNotEmpty($medicines);

        foreach ($medicines as $row) {
            $this->assertArrayHasKey('image_url', $row, 'كل صف يجب أن يحمل المفتاح image_url.');
            // المسار الخام يجب أن يُسقَط — العميل يستلم رابطاً لا مساراً داخلياً.
            $this->assertArrayNotHasKey('image', $row, 'المسار الخام image يجب ألا يتسرّب للعميل.');
        }
    }

    public function test_a_set_image_is_returned_as_a_non_empty_url(): void
    {
        Medicine::create([
            'trade_name' => 'دواء بصورة',
            'active_ingredient' => 'أموكسيسيلين',
            'image' => 'medicines/sample.jpg',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/medicines');

        $response->assertOk();

        $row = collect($response->json('data.medicines'))
            ->firstWhere('trade_name', 'دواء بصورة');

        $this->assertNotNull($row, 'الصف المضاف يجب أن يظهر في الرد.');
        $this->assertIsString($row['image_url']);
        $this->assertNotSame('', $row['image_url'], 'الصورة المضبوطة يجب أن تُحلّ إلى رابط غير فارغ.');
        // مسار مخزّن نسبي (لا يبدأ بـhttp) → يُبنى له رابط مطلق عبر asset().
        $this->assertMatchesRegularExpression(
            '#^https?://#',
            $row['image_url'],
            'الصورة يجب أن تُعاد كرابط مطلق، لا كمسار داخلي خام.',
        );
    }
}
