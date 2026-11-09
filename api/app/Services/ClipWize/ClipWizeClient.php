<?php

namespace App\Services\ClipWize;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter ClipWize (TTS, import klip, publikasi). Mengikuti docs/api-integration-guide.md.
 *
 * - Token hanya dibaca di server dan tidak pernah dicatat/dikembalikan.
 * - GET boleh berpindah alamat saat koneksi gagal; POST hanya berpindah bila permintaan
 *   pasti tidak terkirim (koneksi tidak dapat dibuka). Tidak ada idempotency key,
 *   sehingga publish TIDAK PERNAH diulang otomatis.
 */
class ClipWizeClient
{
    /** Kode galat khusus: hasil POST tidak diketahui (jangan diulang). */
    public const UNKNOWN_OUTCOME = 599;

    private ?string $workingBase = null;

    /**
     * @return array{path: string, bytes: string, duration_seconds: ?float, characters: int}
     */
    public function tts(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('ClipWize TTS: teks kosong.', 422);
        }

        $data = $this->postJson('/tts', ['text' => mb_substr($text, 0, 4000)]);
        $data = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $url = $data['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('ClipWize TTS tidak mengembalikan berkas audio.', 502);
        }

        return [
            'bytes' => $this->download($url),
            'duration_seconds' => isset($data['duration_seconds']) ? (float) $data['duration_seconds'] : null,
            'characters' => (int) ($data['characters'] ?? mb_strlen($text)),
            'format' => (string) ($data['format'] ?? 'mp3'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        $data = $this->get('/social-accounts');

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * Pastikan akun ada, terhubung, dan namanya sesuai harapan. Mengembalikan akun tersebut.
     *
     * @return array<string, mixed>
     */
    public function assertAccount(int $accountId, string $expectedName): array
    {
        foreach ($this->accounts() as $account) {
            if ((int) ($account['id'] ?? 0) !== $accountId) {
                continue;
            }

            $name = (string) ($account['account_name'] ?? $account['name'] ?? $account['username'] ?? '');
            if (mb_strtolower(trim($name)) !== mb_strtolower(trim($expectedName))) {
                throw new RuntimeException("Akun ClipWize #{$accountId} bernama \"{$name}\", bukan \"{$expectedName}\".", 409);
            }

            if (($account['status'] ?? null) !== 'connected') {
                throw new RuntimeException("Akun ClipWize \"{$expectedName}\" belum terhubung.", 409);
            }

            return $account;
        }

        throw new RuntimeException("Akun ClipWize #{$accountId} tidak ditemukan.", 404);
    }

    /**
     * Daftar judul/waktu post terbaru pada satu akun (untuk anti-duplikasi).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentPosts(int $accountId, int $perPage = 50): array
    {
        $data = $this->get('/social-posts', ['per_page' => $perPage, 'social_account_id' => $accountId]);
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];

        return array_values(array_filter($rows, function ($row) use ($accountId): bool {
            $id = $row['social_account_id'] ?? ($row['social_account']['id'] ?? ($row['account']['id'] ?? null));

            return $id === null || (int) $id === $accountId;
        }));
    }

    /**
     * @param  array{title: string, caption: string, hashtags: array<int, string>}  $meta
     * @return array<string, mixed> data klip (memuat id)
     */
    public function importClip(string $absolutePath, array $meta): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Berkas video tidak ditemukan untuk diimpor.', 422);
        }

        $multipart = [
            ['name' => 'title', 'contents' => mb_substr($meta['title'], 0, 100)],
            ['name' => 'caption', 'contents' => $meta['caption']],
        ];
        foreach ($meta['hashtags'] as $tag) {
            $multipart[] = ['name' => 'hashtags[]', 'contents' => ltrim((string) $tag, '#')];
        }

        $handle = fopen($absolutePath, 'rb');
        try {
            $response = $this->send(fn (PendingRequest $http, string $base) => $http
                ->attach('file', $handle, basename($absolutePath))
                ->post($base.'/clips/import', $multipart), idempotent: false, timeout: 1800);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $data = $this->decode($response, 'import klip');
        if (empty($data['data']['id'])) {
            throw new RuntimeException('ClipWize import tidak mengembalikan id klip.', 502);
        }

        return $data['data'];
    }

    /**
     * Publikasikan klip. PUBLIK dan tidak dapat dibatalkan; tidak pernah diulang otomatis.
     *
     * @return array<string, mixed> data post pertama
     */
    public function publish(int $clipId, int $accountId, ?string $scheduledAt = null): array
    {
        $payload = ['clip_id' => $clipId, 'social_account_ids' => [$accountId]];
        if ($scheduledAt !== null) {
            $payload['scheduled_at'] = $scheduledAt;
        }

        $data = $this->postJson('/social-posts', $payload);
        $post = $data['data'][0] ?? $data['data'] ?? $data;

        if (! is_array($post) || empty($post['id'])) {
            throw new RuntimeException('ClipWize publish tidak mengembalikan id post.', self::UNKNOWN_OUTCOME);
        }

        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    public function postStatus(int $postId): array
    {
        $data = $this->get('/social-posts/'.$postId);

        return is_array($data['data'] ?? null) ? $data['data'] : $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function analytics(int $days = 30): array
    {
        return $this->get('/analytics', ['days' => $days]);
    }

    // ---------------------------------------------------------------- transport

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload): array
    {
        $response = $this->send(fn (PendingRequest $http, string $base) => $http->post($base.$path, $payload), idempotent: false);

        return $this->decode($response, $path);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->send(fn (PendingRequest $http, string $base) => $http->get($base.$path, $query), idempotent: true);

        return $this->decode($response, $path);
    }

    private function download(string $url): string
    {
        try {
            $response = Http::timeout(120)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('ClipWize: gagal mengunduh berkas hasil.', 502);
        }

        if (! $response->successful() || $response->body() === '') {
            throw new RuntimeException('ClipWize: gagal mengunduh berkas hasil.', 502);
        }

        return $response->body();
    }

    /**
     * @param  callable(PendingRequest, string): Response  $call
     */
    private function send(callable $call, bool $idempotent, ?int $timeout = null): Response
    {
        $token = $this->token();
        $lastError = null;

        foreach ($this->bases() as $base) {
            $http = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(8)
                ->timeout($timeout ?? max(10, (int) config('services.clipwize.timeout', 180)))
                ->withOptions(['allow_redirects' => false]);

            try {
                $response = $call($http, $base);
            } catch (ConnectionException $exception) {
                $lastError = $exception;
                $notDelivered = $this->connectionNeverOpened($exception);

                if ($idempotent || $notDelivered) {
                    continue;
                }

                throw new RuntimeException('ClipWize: status permintaan TIDAK diketahui (koneksi terputus). Periksa dashboard ClipWize sebelum mengulang.', self::UNKNOWN_OUTCOME);
            }

            // Gateway tanpa respons aplikasi: permintaan pasti belum diproses, aman pindah alamat.
            if (in_array($response->status(), [502, 503, 504], true) && $response->json() === null) {
                $lastError = new RuntimeException('Gateway ClipWize tidak tersedia.');
                continue;
            }

            $this->workingBase = $base;

            return $response;
        }

        throw new RuntimeException('ClipWize tidak terjangkau di alamat mana pun.', 503);
    }

    private function connectionNeverOpened(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (['could not resolve', 'connection refused', 'failed to connect', 'couldn\'t connect', 'curl error 6', 'curl error 7'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $what): array
    {
        if ($response->status() === 401) {
            throw new RuntimeException('ClipWize menolak token (401). Jalankan ulang `clipwize.py login` di PC ini.', 401);
        }

        if (! $response->successful()) {
            $message = $response->json('message');
            $message = is_string($message) && $message !== '' ? mb_substr($message, 0, 200) : 'tanpa pesan';

            throw new RuntimeException("ClipWize {$what} gagal (HTTP {$response->status()}): {$message}", $response->serverError() ? 502 : 422);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<int, string>
     */
    private function bases(): array
    {
        $bases = array_map(fn (string $b) => rtrim($b, '/'), (array) config('services.clipwize.bases', []));
        if ($bases === []) {
            throw new RuntimeException('ClipWize belum dikonfigurasi (CLIPWIZE_BASES).', 503);
        }

        if ($this->workingBase !== null && in_array($this->workingBase, $bases, true)) {
            $bases = array_values(array_unique([$this->workingBase, ...$bases]));
        }

        return $bases;
    }

    private function token(): string
    {
        $token = trim((string) config('services.clipwize.token'));
        if ($token !== '') {
            return $token;
        }

        $file = trim((string) config('services.clipwize.token_file'));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $contents = @file_get_contents($file);
            if (is_string($contents) && trim($contents) !== '') {
                return trim($contents);
            }
        }

        throw new RuntimeException('Token ClipWize belum ada. Jalankan `clipwize.py login` sekali di PC ini.', 401);
    }
}
