<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcrEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->patient()->create();
        config()->set('services.daway_ocr.base_url', 'https://ocr.test');
        config()->set('services.daway_ocr.key', null);
    }

    private function token(): string
    {
        return $this->user->createToken('test')->plainTextToken;
    }

    public function test_ocr_identifies_medicine_and_returns_pharmacies(): void
    {
        Http::fake([
            'ocr.test/ocr/medicine' => Http::response([
                'source' => 'ocr',
                'intent' => 'search_medicine',
                'drug_name' => 'Panadol',
                'ocr_success' => true,
                'status' => 'confirmed',
                'best_candidate' => 'Panadol',
                'match_score' => 0.923,
            ]),
        ]);

        $medicine = Medicine::create([
            'trade_name' => 'Panadol Extra',
            'active_ingredient' => 'Paracetamol',
            'is_available' => true,
        ]);

        $pharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5017,
            'longitude' => 34.4668,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 12.5,
            'quantity' => 4,
            'is_available' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/ocr/medicine', [
                'file' => UploadedFile::fake()->image('box.jpg', 100, 100),
                'latitude' => 31.5016,
                'longitude' => 34.4668,
                'radius_km' => 15,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.drug_name', 'Panadol')
            ->assertJsonPath('data.ocr.success', true);

        $this->assertNotEmpty($response->json('data.results.pharmacies'));
    }

    public function test_ocr_failure_returns_clear_error_without_results(): void
    {
        Http::fake([
            'ocr.test/ocr/medicine' => Http::response([
                'ocr_success' => false,
                'drug_name' => null,
                'message' => 'لم يتم التعرف',
            ]),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/ocr/medicine', [
                'file' => UploadedFile::fake()->image('blur.jpg', 10, 10),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ocr.success', false)
            ->assertJsonPath('data.results', null);
    }

    public function test_ocr_service_down_does_not_crash(): void
    {
        Http::fake(fn () => Http::response([], 500));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/ocr/medicine', [
                'file' => UploadedFile::fake()->image('box.jpg', 10, 10),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ocr.success', false)
            ->assertJsonPath('data.results', null);
    }

    public function test_ocr_requires_authentication(): void
    {
        $this->postJson('/api/ocr/medicine', [])
            ->assertStatus(401);
    }

    public function test_ocr_validates_file_is_image(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/ocr/medicine', [
                'file' => UploadedFile::fake()->create('doc.pdf', 100),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
