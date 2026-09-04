<?php

namespace Tests\Feature\Api\Patient;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantAnalyzeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(array $body = [], int $status = 200): void
    {
        config(['services.daway_ai.base_url' => 'https://ai.test']);

        Http::fake([
            'ai.test/ai/assistant' => Http::response($body ?: [
                'source' => 'gemini',
                'intent' => 'search_medicine',
                'drug_name' => 'paracetamol',
                'symptoms' => [],
                'requires_location' => false,
            ], $status),
        ]);
    }

    public function test_assistant_analyze_requires_authentication(): void
    {
        $this->fakeAi();

        $response = $this->postJson('/api/patient/assistant/analyze', [
            'message' => 'where can I find panadol?',
        ]);

        $response->assertStatus(401);
    }

    public function test_assistant_analyze_returns_analysis_object(): void
    {
        $this->fakeAi();

        $patient = User::factory()->patient()->create();
        $this->actingAs($patient, 'sanctum');

        $response = $this->postJson('/api/patient/assistant/analyze', [
            'message' => 'where can I find panadol?',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.analysis.intent', 'search_medicine')
            ->assertJsonPath('data.analysis.drug_name', 'paracetamol')
            ->assertJsonPath('data.analysis.source', 'gemini');
    }

    public function test_assistant_analyze_returns_pharmacies_array_even_if_empty(): void
    {
        $this->fakeAi();

        $patient = User::factory()->patient()->create();
        $this->actingAs($patient, 'sanctum');

        $response = $this->postJson('/api/patient/assistant/analyze', [
            'message' => 'I need paracetamol',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'results' => ['pharmacies'],
                ],
            ]);

        $pharmacies = $response->json('data.results.pharmacies');

        $this->assertIsArray($pharmacies);
        $this->assertSame([], $pharmacies);
    }

    public function test_assistant_analyze_validates_message_required(): void
    {
        $this->fakeAi();

        $patient = User::factory()->patient()->create();
        $this->actingAs($patient, 'sanctum');

        $this->postJson('/api/patient/assistant/analyze', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_assistant_analyze_validates_message_max_500_chars(): void
    {
        $this->fakeAi();

        $patient = User::factory()->patient()->create();
        $this->actingAs($patient, 'sanctum');

        $this->postJson('/api/patient/assistant/analyze', [
            'message' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }
}