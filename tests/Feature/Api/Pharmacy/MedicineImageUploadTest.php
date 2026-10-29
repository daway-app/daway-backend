<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_an_uploaded_image_file(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);
        Storage::fake('public');

        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $user->id]);
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 12.5,
            'quantity' => 4,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('medicine.jpg'),
        ])->assertCreated();

        $image = $medicine->fresh()->image;

        $this->assertNotNull($image);
        $this->assertStringStartsWith('medicines/', $image);
        Storage::disk('public')->assertExists($image);
    }

    public function test_uploaded_file_wins_over_image_url(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);
        Storage::fake('public');

        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $user->id]);
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $url = 'https://res.cloudinary.com/demo/image/upload/sample.jpg';

        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 9,
            'quantity' => 1,
            'image_url' => $url,
            'image_file' => UploadedFile::fake()->image('medicine.png'),
        ])->assertCreated();

        $this->assertNotSame($url, $medicine->fresh()->image);
    }

    /**
     * 🔒 **حارس الثغرة**: صيدلية ثانية لا تدهس صورة الكتالوج المشترك.
     *
     * `medicines` جدول عام تشترك فيه كل الصيدليات. قبل الإصلاح كانت أي
     * صيدلية ترفع صورة فتُغيّرها عند الجميع، و`Cloudinary::deleteLocal`
     * كان يحذف ملف الصورة السابق — أي حذف أصل تملكه صيدلية أخرى.
     *
     * القاعدة الآن: الكتابة على الكتالوج مقصورة على الصيدلية **الوحيدة**
     * المستخدمة للدواء (`MedicineCatalogService::isSoleUser`).
     */
    public function test_second_pharmacy_cannot_overwrite_the_shared_catalog_image(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);
        Storage::fake('public');

        $medicine = Medicine::factory()->create();

        // الصيدلية الأولى (المستخدمة الوحيدة آنذاك) تضع صورتها.
        $userA = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userA->id]);

        Sanctum::actingAs($userA);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 3,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        $originalImage = $medicine->fresh()->image;
        $this->assertNotNull($originalImage);

        // الصيدلية الثانية تضيف نفس الدواء بصورة مختلفة.
        $userB = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userB);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 11,
            'quantity' => 2,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('b.jpg'),
        ])->assertCreated();

        // صورة الكتالوج لم تتغيّر، وملف الصيدلية الأولى لم يُحذف.
        $this->assertSame($originalImage, $medicine->fresh()->image);
        Storage::disk('public')->assertExists($originalImage);
    }

    /**
     * وحتى الصيدلية الأولى تفقد حق الكتابة بعد انضمام ثانية — البوابة على
     * السجل المشترك لا على من كتب أولًا.
     */
    public function test_first_pharmacy_loses_write_access_once_medicine_is_shared(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);
        Storage::fake('public');

        $medicine = Medicine::factory()->create();

        $userA = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userA->id]);
        $userB = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 3,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        $originalImage = $medicine->fresh()->image;

        Sanctum::actingAs($userB);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 11,
            'quantity' => 1,
            'is_available' => true,
        ])->assertCreated();

        // الآن السجل مشترك: صيدلية A تحاول الدهس فلا تنجح.
        Sanctum::actingAs($userA);
        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 12,
            'quantity' => 1,
            'is_available' => true,
            'image_url' => 'https://res.cloudinary.com/demo/image/upload/overwrite.jpg',
        ])->assertStatus(422); // الدواء مضاف مسبقاً لمخزونها

        $this->assertSame($originalImage, $medicine->fresh()->image);
    }

    /**
     * 🔒 **حارس A3 (اتساق الملفات)**: بوابة الحكر تُفحص **قبل** الرفع،
     * فلا يُنشأ ملف يتيم في Cloudinary/التخزين المحلي لمحاولة مرفوضة.
     *
     * كانت الكتابة القديمة ترفع الملف ثم تكتشف أنها غير مصرّحة، فيبقى
     * الملف بلا مرجع في السجل. الآن: بلا بوابة ⇒ لا رفع أصلًا.
     */
    public function test_rejected_image_write_does_not_upload_an_orphan_file(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);
        Storage::fake('public');

        $medicine = Medicine::factory()->create();

        $userA = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userA->id]);
        $userB = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 3,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        $filesBefore = Storage::disk('public')->allFiles('medicines');
        $this->assertNotEmpty($filesBefore);

        // الصيدلية الثانية تضيف نفس الدواء بملف — سيُرفض (سجل مشترك).
        Sanctum::actingAs($userB);
        $this->post('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 11,
            'quantity' => 1,
            'is_available' => true,
            'image_file' => UploadedFile::fake()->image('b.jpg'),
        ])->assertCreated();

        // لا ملف جديد ظهر: البوابة رفضت قبل الرفع.
        $this->assertSame(
            $filesBefore,
            Storage::disk('public')->allFiles('medicines'),
            'رفض الكتابة على كتالوج مشترك يجب ألا يترك ملفًا مرفوعًا.'
        );
    }
}
