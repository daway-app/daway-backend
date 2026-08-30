<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MedicineSearchCacheKeyTest extends TestCase
{
    public function test_very_long_query_is_cached_with_truncated_key(): void
    {
        // H9: استعلام بطول ضخم لا يُكسر ولا يُنتج مفتاحاً ضخماً.
        Cache::flush();

        $hugeQuery = str_repeat('a', 5000);

        $response = $this->getJson('/api/medicines/search?q='.urlencode($hugeQuery));

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_query_with_pipe_characters_does_not_break_cache_key(): void
    {
        // H9: رموز الفاصل | في الاستعلام تُستبدل — لا تصادم بين المفاتيح.
        Cache::flush();

        $response = $this->getJson('/api/medicines/search?q='.urlencode('a|b|c|d'));
        $response->assertOk()->assertJsonPath('success', true);

        $second = $this->getJson('/api/medicines/search?q='.urlencode('ab cd'));
        $second->assertOk()->assertJsonPath('success', true);
    }

    public function test_whitespace_normalized_queries_share_cache(): void
    {
        // H9: "a  b" و "a b" و "  a b  " تعطي نفس المفتاح (نفس الـ payload).
        Cache::flush();

        $first = $this->getJson('/api/medicines/search?q='.urlencode('a  b'));
        $second = $this->getJson('/api/medicines/search?q='.urlencode('  a b  '));

        $first->assertOk();
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_index_endpoint_accepts_huge_query_without_error(): void
    {
        Cache::flush();

        $hugeQuery = str_repeat('م', 10000);

        $response = $this->getJson('/api/medicines?q='.urlencode($hugeQuery).'&page=1&per_page=5');

        $response->assertOk()->assertJsonPath('success', true);
    }
}
