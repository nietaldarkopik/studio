<?php

namespace App\Services\Search;

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
