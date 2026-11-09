<?php

namespace Tests\Feature;

use App\Services\Search\SearchClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchViaNineRouterTest extends TestCase
{
    public function test_search_uses_nine_router_when_tavily_key_is_missing(): void
    {
        config([
            'services.tavily.api_key' => null,
            'services.nine_router.base_url' => 'http://localhost:20128/v1',
            'services.nine_router.api_key' => 'router-key',
            'services.nine_router.api_key_file' => null,
        ]);

        Http::fake([
            'http://localhost:20128/v1/search' => Http::response(['results' => [
                ['title' => 'A', 'url' => 'https://a.example.com/x', 'snippet' => 'isi A', 'score' => 0.9],
                ['title' => 'Tanpa teks', 'url' => 'https://b.example.com/y', 'snippet' => ''],
                ['title' => 'C', 'url' => 'https://c.example.org/z', 'content' => 'isi C'],
            ]]),
        ]);

        $results = app(SearchClient::class)->search('apa saja');

        $this->assertSame(['https://a.example.com/x', 'https://c.example.org/z'], array_column($results, 'url'));
        Http::assertSent(fn ($r) => $r->url() === 'http://localhost:20128/v1/search'
            && $r['provider'] === 'tavily'
            && $r->hasHeader('Authorization', 'Bearer router-key'));
    }
}
