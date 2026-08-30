<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiChatFailureHandlingTest extends TestCase
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

    public function test_chat_survives_ai_service_unavailable(): void
    {
        // H8: فشل خدمة الـ AI (500) — الـ client يرجع fallback، والرد 200 مع results=null.
        Http::fake([
            'ai.test/ai/assistant' => Http::response(['error' => 'unavailable'], 500),
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/chat', ['message' => 'أبحث عن بنادول'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results', null)
            ->assertJsonPath('data.analysis.intent', 'unknown')
            ->assertJsonPath('data.analysis.source', 'fallback');
    }

    public function test_chat_survives_ai_service_timeout(): void
    {
        // H8: انقطاع الاتصال بخدمة AI — لا exception، fallback آمن.
        Http::fake([
            'ai.test/ai/assistant' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/chat', ['message' => 'أبحث عن بنادول'])
            ->assertOk()
            ->assertJsonPath('data.results', null)
            ->assertJsonPath('data.analysis.source', 'fallback');
    }

    public function test_chat_survives_malformed_ai_response(): void
    {
        // H8: شكل رد غير متوقع من الـ AI — data_get يمنع undefined index.
        Http::fake([
            'ai.test/ai/assistant' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson('/api/chat', ['message' => 'أبحث عن بنادول'])
            ->assertOk()
            ->assertJsonPath('data.analysis.intent', 'unknown')
            ->assertJsonPath('data.analysis.drug_name', null);
    }
}
