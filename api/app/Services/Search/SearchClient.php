<?php

namespace App\Services\Search;

use App\Services\AI\NineRouterClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien mesin pencari untuk kebutuhan riset. Penyedia default: Tavily.
 */
class SearchClient
{
    /**
     * @return array<int, array{title: string, url: string, snippet: string, published_at: ?string, score: float}>
     */
    public function search(string $query, ?int $maxResults = null): array
    {
        if ($this->apiKey() === null && config('services.search.via_nine_router', true)) {
            $routed = $this->nineRouterSearch($query, $maxResults ?? (int) config('services.tavily.max_results', 6));
            if ($routed !== []) {
                return $routed;
            }
        }

        if ($this->apiKey() === null && config('services.tavily.keyless_fallback', true)) {
            return $this->keylessSearch($query, $maxResults ?? (int) config('services.tavily.max_results', 6));
        }

        $payload = $this->request('/search', [
            'query' => $query,
            'search_depth' => (string) config('services.tavily.search_depth', 'basic'),
            'topic' => 'general',
            'max_results' => $maxResults ?? (int) config('services.tavily.max_results', 6),
            'include_answer' => false,
            'include_raw_content' => false,
            'include_images' => false,
        ]);

        $results = [];

        foreach ((array) ($payload['results'] ?? []) as $result) {
            $url = is_array($result) ? ($result['url'] ?? null) : null;

            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $results[] = [
                'title' => $this->text($result['title'] ?? null, 200) ?? $this->host($url),
                'url' => $url,
                'snippet' => $this->text($result['content'] ?? null, 1200) ?? '',
                'published_at' => $this->date($result['published_date'] ?? null),
                'score' => is_numeric($result['score'] ?? null) ? round((float) $result['score'], 3) : 0.0,
            ];
        }

        return $results;
    }

    /**
     * Ambil teks halaman sumber agar AI bisa membaca isinya.
     * Mengembalikan null bila penyedia belum dikonfigurasi atau halaman tidak dapat dibaca.
     *
     * @return array{text: string}|null
     */
    public function extract(string $url): ?array
    {
        if ($this->apiKey() === null) {
            return null;
        }

        try {
            $payload = $this->request('/extract', [
                'urls' => [$url],
                'extract_depth' => 'basic',
                'format' => 'markdown',
            ]);
        } catch (RuntimeException) {
            return null;
        }

        $text = $payload['results'][0]['raw_content'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return ['text' => mb_substr(trim($text), 0, 12000)];
    }

    /**
     * Pencarian tanpa kunci API (cadangan bila Tavily belum dikonfigurasi): hanya API resmi Wikipedia.
     * Satu organisasi tidak dihitung sumber independen ganda; riset multi-situs tetap butuh TAVILY_API_KEY.
     * Hasil tetap perlu dikurasi dan diverifikasi seperti hasil Tavily.
     *
     * @return array<int, array{title: string, url: string, snippet: string, published_at: ?string, score: float}>
     */
    private function keylessSearch(string $query, int $max): array
    {
        $results = [];

        // Wikipedia mensyaratkan semua kata cocok; coba kueri penuh lalu versi yang dipersingkat.
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $variants = array_values(array_unique(array_filter([
            $query,
            implode(' ', array_slice($words, 0, 3)),
            implode(' ', array_slice($words, 0, 2)),
        ])));

        foreach (['id', 'en'] as $lang) {
            foreach ($variants as $variant) {
                $found = $this->wikipedia($lang, $variant);
                if ($found !== []) {
                    array_push($results, ...$found);
                    break;
                }
            }
        }

        $seen = [];
        $unique = [];
        foreach ($results as $result) {
            if (! isset($seen[$result['url']])) {
                $seen[$result['url']] = true;
                $unique[] = $result;
            }
        }

        return array_slice($unique, 0, max(1, $max));
    }

    /**
     * Pencarian web lewat endpoint /v1/search milik 9Router (memakai penyedia yang sudah
     * terkonfigurasi di sana, mis. Tavily/Exa), sehingga tak perlu kunci terpisah di aplikasi ini.
     *
     * @return array<int, array{title: string, url: string, snippet: string, published_at: ?string, score: float}>
     */
    private function nineRouterSearch(string $query, int $max): array
    {
        $baseUrl = rtrim((string) config('services.nine_router.base_url'), '/');
        $key = app(NineRouterClient::class)->resolvedApiKey();
        if ($key === null || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return [];
        }

        foreach ((array) config('services.search.nine_router_providers', ['tavily', 'exa']) as $provider) {
            try {
                $response = Http::connectTimeout(5)->timeout(40)->withToken($key)->acceptJson()->asJson()
                    ->post("{$baseUrl}/search", ['provider' => $provider, 'query' => $query, 'max_results' => $max]);
            } catch (ConnectionException) {
                return [];
            }

            if (! $response->successful()) {
                continue;
            }

            $results = [];
            foreach ((array) $response->json('results', []) as $row) {
                $url = is_array($row) ? ($row['url'] ?? null) : null;
                $snippet = is_array($row) ? $this->text($row['snippet'] ?? ($row['content'] ?? null), 1200) : null;

                // Tanpa teks pendukung AI cenderung mengarang temuan; lewati.
                if (! is_string($url) || $snippet === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    continue;
                }

                $results[] = [
                    'title' => $this->text($row['title'] ?? null, 200) ?? $this->host($url),
                    'url' => $url,
                    'snippet' => $snippet,
                    'published_at' => $this->date($row['published_at'] ?? null),
                    'score' => is_numeric($row['score'] ?? null) ? round((float) $row['score'], 3) : 0.5,
                ];
            }

            if ($results !== []) {
                return array_slice($results, 0, max(1, $max));
            }
        }

        return [];
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string, published_at: ?string, score: float}>
     */
    private function wikipedia(string $lang, string $query): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->acceptJson()
                ->withHeaders(['User-Agent' => 'TernyataBeginiStudio/1.0 (research assistant)'])
                ->get("https://{$lang}.wikipedia.org/w/api.php", [
                    'action' => 'query', 'list' => 'search', 'srsearch' => $query,
                    'srlimit' => 3, 'format' => 'json', 'utf8' => 1,
                ]);
        } catch (ConnectionException) {
            return [];
        }

        $found = [];
        foreach ((array) $response->json('query.search', []) as $row) {
            $title = is_array($row) ? ($row['title'] ?? null) : null;
            if (! is_string($title)) {
                continue;
            }

            $found[] = [
                'title' => $this->text($title, 200) ?? $title,
                'url' => "https://{$lang}.wikipedia.org/wiki/".str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title))),
                'snippet' => $this->text(html_entity_decode(strip_tags((string) ($row['snippet'] ?? ''))), 1200) ?? '',
                'published_at' => null,
                'score' => 0.5,
            ];
        }

        return $found;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new RuntimeException('Tavily belum dikonfigurasi.', 503);
        }

        $baseUrl = rtrim((string) config('services.tavily.base_url', 'https://api.tavily.com'), '/');
        $timeout = (int) config('services.tavily.timeout', 30);

        try {
            $response = Http::connectTimeout(5)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}{$path}", $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tavily tidak merespons dalam batas waktu.', 503, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Tavily menolak permintaan (HTTP '.$response->status().').', 502);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('Tavily mengembalikan respons yang tidak dapat dibaca.', 502);
        }

        return $decoded;
    }

    private function apiKey(): ?string
    {
        $key = config('services.tavily.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : $url;
    }
}
