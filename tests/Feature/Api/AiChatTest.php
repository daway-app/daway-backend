<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->patient()->create();
        config()->set('services.daway_ai.base_url', 'https://ai.test');
        config()->set('services.daway_ai.key', null);
    }

    private function token(): string
    {
        return $this->user->createToken('test')->plainTextToken;
    }

    public function test_chat_returns_pharmacies_for_ai_detected_medicine(): void
    {
        Http::fake([
            'ai.test/ai/assistant' => Http::response([
                'source' => 'gemini',
                'intent' => 'search_medicine',
                'drug_name' => 'بانادول',
                'symptoms' => [],
                'requires_location' => true,
            ]),
        ]);

        $medicine = Medicine::create([
            'trade_name' => 'بانادول اكسترا',
            'active_ingredient' => 'باراسيتامول',
            'is_available' => true,
        ]);

        $nearPharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5017,
            'longitude' => 34.4668,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $nearPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $farPharmacy = Pharmacy::factory()->create([
            'latitude' => 32.0,
            'longitude' => 35.0,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $farPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 3,
            'is_available' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/chat', [
                'message' => 'وين بلاقي بانادول؟',
                'latitude' => 31.5016,
                'longitude' => 34.4668,
                'radius_km' => 15,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.analysis.drug_name', 'بانادول');

        $pharmacies = $response->json('data.results.pharmacies');
        $this->assertNotEmpty($pharmacies);
        // الأقرب أولاً
        $this->assertEquals($nearPharmacy->id, $pharmacies[0]['pharmacy_id']);
        $this->assertNotNull($pharmacies[0]['distance_km']);
    }

    public function test_chat_unknown_intent_returns_empty_results(): void
    {
        Http::fake([
            'ai.test/ai/assistant' => Http::response([
                'intent' => 'greeting',
                'drug_name' => null,
                'symptoms' => [],
            ]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/chat', ['message' => 'مرحبا'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results', null);
    }

    public function test_chat_falls_back_gracefully_when_ai_service_down(): void
    {
        Http::fake(fn () => Http::response([], 500));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/chat', ['message' => 'وين بلاقي بانادول؟']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.analysis.source', 'fallback')
            ->assertJsonPath('data.analysis.intent', 'unknown');
    }

    public function test_chat_requires_authentication(): void
    {
        $this->postJson('/api/chat', ['message' => 'test'])
            ->assertStatus(401);
    }

    public function test_chat_validates_message(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/chat', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }
}
