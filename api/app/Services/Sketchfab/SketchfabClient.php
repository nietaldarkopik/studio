<?php

namespace App\Services\Sketchfab;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Pencari & pengunduh model 3D Sketchfab (Data API v3).
 *
 * - Hanya model yang dapat diunduh dengan lisensi pada allowlist (default: CC0 dan CC-BY).
 * - Token hanya dibaca di server (SKETCHFAB_API_TOKEN) dan tidak pernah dicatat.
 * - ZIP diekstrak dengan pemeriksaan path dan ukuran; hanya ekstensi model/tekstur yang diizinkan.
 */
class SketchfabClient
{
    private const API = 'https://api.sketchfab.com/v3';

    private const ALLOWED_EXT = ['gltf', 'glb', 'bin', 'png', 'jpg', 'jpeg', 'webp', 'ktx2'];

    private const LICENSE_LABELS = [
        'cc0' => 'CC0 1.0',
        'by' => 'CC BY 4.0',
        'by-sa' => 'CC BY-SA 4.0',
        'by-nd' => 'CC BY-ND 4.0',
        'by-nc' => 'CC BY-NC 4.0',
    ];

    private ?string $lastError = null;

    public function isConfigured(): bool
    {
        return $this->token() !== null;
    }

    /** Alasan pencarian terakhir gagal/kosong (untuk diagnosis), tanpa data rahasia. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array{uid: string, name: string, author: string, url: string, license: string, license_slug: string}|null
     */
    public function find(string $query): ?array
    {
        $token = $this->token();
        $query = trim($query);
        if ($token === null || $query === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$token])->acceptJson()
                ->connectTimeout(8)->timeout(30)
                ->get(self::API.'/search', [
                    'type' => 'models', 'q' => $query, 'downloadable' => 'true',
                    'sort_by' => '-likeCount', 'count' => 24, 'max_face_count' => (int) config('services.sketchfab.max_faces', 120000),
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() === 202 && $response->body() === '') {
            $this->lastError = 'Sketchfab menahan permintaan server ini (HTTP 202 kosong: pemeriksaan bot pada gerbang API). Tidak dapat diakses dari aplikasi ini saat ini.';

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'Sketchfab menolak pencarian (HTTP '.$response->status().').';

            return null;
        }

        $allowed = $this->allowedLicenses();
        foreach ((array) $response->json('results', []) as $row) {
            $slug = strtolower((string) ($row['license']['slug'] ?? ''));
            $uid = (string) ($row['uid'] ?? '');
            if ($uid === '' || ! in_array($slug, $allowed, true) || ! ($row['isDownloadable'] ?? true)) {
                continue;
            }

            return [
                'uid' => $uid,
                'name' => mb_substr((string) ($row['name'] ?? 'Model 3D'), 0, 120),
                'author' => mb_substr((string) ($row['user']['displayName'] ?? $row['user']['username'] ?? 'Unknown'), 0, 80),
                'url' => (string) ($row['viewerUrl'] ?? 'https://sketchfab.com/3d-models/'.$uid),
                'license' => self::LICENSE_LABELS[$slug] ?? strtoupper($slug),
                'license_slug' => $slug,
            ];
        }

        return null;
    }

    /**
     * Unduh dan ekstrak model; mengembalikan path berkas .gltf/.glb lokal (di-cache per uid).
     */
    public function download(string $uid, string $cacheRoot): string
    {
        $token = $this->token();
        if ($token === null) {
            throw new RuntimeException('Sketchfab belum dikonfigurasi (SKETCHFAB_API_TOKEN).', 503);
        }
        if (! preg_match('/^[0-9a-f]{32}$/i', $uid)) {
            throw new RuntimeException('Sketchfab: uid model tidak valid.', 422);
        }

        $dir = rtrim($cacheRoot, '/\\').DIRECTORY_SEPARATOR.$uid;
        $existing = $this->modelFile($dir);
        if ($existing !== null) {
            return $existing;
        }

        $meta = Http::withHeaders(['Authorization' => 'Token '.$token])->acceptJson()
            ->connectTimeout(8)->timeout(30)->get(self::API."/models/{$uid}/download");
        if (! $meta->successful()) {
            throw new RuntimeException('Sketchfab menolak unduhan model (HTTP '.$meta->status().').', 502);
        }

        $url = (string) ($meta->json('glb.url') ?? $meta->json('gltf.url') ?? '');
        $size = (int) ($meta->json('glb.size') ?? $meta->json('gltf.size') ?? 0);
        $maxBytes = (int) config('services.sketchfab.max_download_mb', 80) * 1024 * 1024;
        if (! str_starts_with($url, 'https://') || ($size > 0 && $size > $maxBytes)) {
            throw new RuntimeException('Sketchfab: tautan unduhan tidak valid atau terlalu besar.', 502);
        }

        File::ensureDirectoryExists($dir);
        $isGlb = str_contains(parse_url($url, PHP_URL_PATH) ?? '', '.glb');
        $archive = $dir.DIRECTORY_SEPARATOR.($isGlb ? 'model.glb' : 'model.zip');

        $response = Http::connectTimeout(10)->timeout(300)->sink($archive)->get($url);
        if (! $response->successful() || ! is_file($archive) || filesize($archive) > $maxBytes) {
            @unlink($archive);
            throw new RuntimeException('Sketchfab: gagal mengunduh berkas model.', 502);
        }

        if (! $isGlb) {
            $this->extract($archive, $dir, $maxBytes);
            @unlink($archive);
        }

        $file = $this->modelFile($dir);
        if ($file === null) {
            throw new RuntimeException('Sketchfab: arsip tidak berisi model glTF.', 502);
        }

        return $file;
    }

    /**
     * Teks kredit untuk deskripsi video (wajib untuk lisensi CC BY).
     *
     * @param  array<int, array<string, mixed>>  $credits
     */
    public static function creditsText(array $credits): string
    {
        if ($credits === []) {
            return '';
        }

        $lines = array_map(
            fn (array $c) => '• "'.$c['name'].'" oleh '.$c['author'].' ('.$c['license'].(isset($c['source']) ? ', '.$c['source'] : '').') — '.$c['url'],
            $credits,
        );

        return "Model 3D:\n".implode("\n", $lines);
    }

    // ------------------------------------------------------------------ internals

    private function extract(string $zipPath, string $dir, int $maxBytes): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Sketchfab: arsip model rusak.', 502);
        }

        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                $clean = str_replace('\\', '/', $name);

                if (str_ends_with($clean, '/')) {
                    continue;
                }
                if (str_contains($clean, '..') || str_starts_with($clean, '/') || preg_match('/^[A-Za-z]:/', $clean)) {
                    throw new RuntimeException('Sketchfab: arsip memuat path berbahaya.', 502);
                }
                if (! in_array(strtolower(pathinfo($clean, PATHINFO_EXTENSION)), self::ALLOWED_EXT, true)) {
                    continue; // abaikan berkas non-model
                }

                $total += (int) ($stat['size'] ?? 0);
                if ($total > $maxBytes * 4) {
                    throw new RuntimeException('Sketchfab: arsip terlalu besar setelah diekstrak.', 502);
                }

                $target = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $clean);
                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStream($name);
                if ($in === false) {
                    continue;
                }
                $out = fopen($target, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    private function modelFile(string $dir): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        foreach (['glb', 'gltf'] as $ext) {
            $found = glob($dir.DIRECTORY_SEPARATOR.'*.'.$ext) ?: glob($dir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.'.$ext) ?: [];
            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function allowedLicenses(): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) config('services.sketchfab.allowed_licenses', 'cc0,by')),
        )));
    }

    private function token(): ?string
    {
        $token = trim((string) config('services.sketchfab.token'));

        return $token !== '' ? $token : null;
    }
}
