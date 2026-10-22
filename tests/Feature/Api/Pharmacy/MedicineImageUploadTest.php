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
}
