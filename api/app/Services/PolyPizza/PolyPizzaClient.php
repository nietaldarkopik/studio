<?php

namespace App\Services\PolyPizza;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pencari & pengunduh model low-poly dari Poly Pizza (poly.pizza).
 *
 * Dokumentasi resmi API tidak tersedia saat adapter ini ditulis, sehingga parsing respons dibuat
 * toleran terhadap variasi nama field. Jalankan `php artisan studio:check-assets` dengan key asli
 * untuk memastikan bentuk respons.
 *
 * - Key hanya dibaca di server (POLYPIZZA_API_KEY) dan tidak pernah dicatat.
 * - Hanya lisensi pada allowlist (default CC0 dan CC-BY). CC-BY wajib atribusi.
 * - Berkas diverifikasi sebagai GLB (magic "glTF") dan dibatasi ukurannya.
 */
class PolyPizzaClient
{
    private const BASE = 'https://api.poly.pizza/v1.1';

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * @return array{uid: string, name: string, author: string, url: string, license: string, license_slug: string, download: string, attribution: ?string}|null
     */
    public function find(string $query): ?array
    {
        $key = $this->key();
        $query = trim($query);
        if ($key === null || $query === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['x-auth-token' => $key])->acceptJson()
                ->connectTimeout(8)->timeout(30)
                ->get(self::BASE.'/search/'.rawurlencode($query), ['Limit' => 24]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $allowed = $this->allowedLicenses();
        foreach ((array) ($response->json('results') ?? $response->json('data') ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) $this->pick($row, ['ID', 'id', 'Id']);
            $download = (string) $this->pick($row, ['Download', 'download', 'DownloadURL', 'url']);
            $licenseText = (string) $this->pick($row, ['Licence', 'License', 'licence', 'license']);
            $slug = $this->licenseSlug($licenseText);

            if ($id === '' || ! str_starts_with($download, 'https://') || $slug === null || ! in_array($slug, $allowed, true)) {
                continue;
            }

            $creator = $row['Creator'] ?? $row['creator'] ?? [];
            $author = is_array($creator) ? (string) $this->pick($creator, ['Username', 'username', 'Name', 'name']) : (string) $creator;

            return [
                'uid' => preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? $id,
                'name' => mb_substr((string) ($this->pick($row, ['Title', 'title', 'Name', 'name']) ?: 'Model 3D'), 0, 120),
                'author' => mb_substr($author !== '' ? $author : 'Unknown', 0, 80),
                'url' => 'https://poly.pizza/m/'.rawurlencode($id),
                'license' => $licenseText !== '' ? $licenseText : strtoupper($slug),
                'license_slug' => $slug,
                'download' => $download,
                'attribution' => ($a = $this->pick($row, ['Attribution', 'attribution'])) ? mb_substr((string) $a, 0, 300) : null,
            ];
        }

        return null;
    }

    /**
     * Unduh GLB ke cache dan kembalikan path lokal.
     *
     * @param  array{uid: string, download: string}  $found
     */
    public function download(array $found, string $cacheRoot): string
    {
        $uid = preg_replace('/[^A-Za-z0-9_-]/', '', $found['uid']) ?: '';
        if ($uid === '' || ! str_starts_with($found['download'], 'https://')) {
            throw new RuntimeException('Poly Pizza: data model tidak valid.', 422);
        }

        $dir = rtrim($cacheRoot, '/\\').DIRECTORY_SEPARATOR.$uid;
        $file = $dir.DIRECTORY_SEPARATOR.'model.glb';
        if (is_file($file) && filesize($file) > 0) {
            return $file;
        }

        File::ensureDirectoryExists($dir);
        $maxBytes = (int) config('services.polypizza.max_download_mb', 40) * 1024 * 1024;

        $response = Http::connectTimeout(10)->timeout(180)->sink($file)->get($found['download']);
        if (! $response->successful() || ! is_file($file) || filesize($file) === 0 || filesize($file) > $maxBytes) {
            @unlink($file);
            throw new RuntimeException('Poly Pizza: gagal mengunduh model.', 502);
        }

        $magic = file_get_contents($file, false, null, 0, 4);
        if ($magic !== 'glTF') {
            @unlink($file);
            throw new RuntimeException('Poly Pizza: berkas bukan GLB yang valid.', 502);
        }

        return $file;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return '';
    }

    private function licenseSlug(string $text): ?string
    {
        $t = strtolower($text);
        if (str_contains($t, 'cc0') || str_contains($t, 'public domain')) {
            return 'cc0';
        }
        if (str_contains($t, 'by-nc') || str_contains($t, 'by-nd') || str_contains($t, 'by-sa')) {
            return null;
        }
        if (str_contains($t, 'cc-by') || str_contains($t, 'cc by') || $t === 'by') {
            return 'by';
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
            explode(',', (string) config('services.polypizza.allowed_licenses', 'cc0,by')),
        )));
    }

    private function key(): ?string
    {
        $key = trim((string) config('services.polypizza.key'));

        return $key !== '' ? $key : null;
    }
}
