<?php

namespace Tests\Feature;

use App\Services\Sketchfab\SketchfabClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SketchfabClientTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio_sketchfab_test';
        File::deleteDirectory($this->cache);
        config(['services.sketchfab.token' => 'sf-token', 'services.sketchfab.allowed_licenses' => 'cc0,by']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cache);
        parent::tearDown();
    }

    public function test_find_skips_disallowed_licenses_and_returns_attribution_data(): void
    {
        Http::fake(['api.sketchfab.com/v3/search*' => Http::response(['results' => [
            ['uid' => str_repeat('a', 32), 'name' => 'NC model', 'license' => ['slug' => 'by-nc'], 'user' => ['displayName' => 'X']],
            ['uid' => str_repeat('b', 32), 'name' => 'Bumi', 'license' => ['slug' => 'by'], 'user' => ['displayName' => 'Budi'], 'viewerUrl' => 'https://sketchfab.com/3d-models/bumi-bbbb'],
        ]])]);

        $found = app(SketchfabClient::class)->find('earth planet');

        $this->assertSame(str_repeat('b', 32), $found['uid']);
        $this->assertSame('CC BY 4.0', $found['license']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Token sf-token') && str_contains($r->url(), 'downloadable=true'));
        $this->assertStringContainsString('"Bumi" oleh Budi (CC BY 4.0)', SketchfabClient::creditsText([$found]));
    }

    public function test_not_configured_returns_null_without_calling_the_api(): void
    {
        config(['services.sketchfab.token' => null]);
        Http::fake();

        $this->assertFalse(app(SketchfabClient::class)->isConfigured());
        $this->assertNull(app(SketchfabClient::class)->find('earth'));
        Http::assertNothingSent();
    }

    public function test_download_extracts_gltf_zip_and_caches_it(): void
    {
        $uid = str_repeat('c', 32);
        Http::fake([
            "api.sketchfab.com/v3/models/{$uid}/download" => Http::response(['gltf' => ['url' => 'https://cdn.example.com/m.zip', 'size' => 100]]),
            'cdn.example.com/*' => Http::response($this->zip(['scene.gltf' => '{}', 'scene.bin' => 'x', 'textures/a.png' => 'p', 'README.txt' => 'abaikan'])),
        ]);

        $client = app(SketchfabClient::class);
        $path = $client->download($uid, $this->cache);

        $this->assertStringEndsWith('scene.gltf', $path);
        $this->assertFileExists(dirname($path).DIRECTORY_SEPARATOR.'textures'.DIRECTORY_SEPARATOR.'a.png');
        $this->assertFileDoesNotExist(dirname($path).DIRECTORY_SEPARATOR.'README.txt');

        // panggilan kedua memakai cache (tidak ada permintaan baru)
        Http::fake()->preventStrayRequests();
        $this->assertSame($path, $client->download($uid, $this->cache));
    }

    public function test_download_rejects_path_traversal_and_bad_urls(): void
    {
        $uid = str_repeat('d', 32);
        Http::fake([
            "api.sketchfab.com/v3/models/{$uid}/download" => Http::response(['gltf' => ['url' => 'https://cdn.example.com/m.zip', 'size' => 100]]),
            'cdn.example.com/*' => Http::response($this->zip(['../../evil.gltf' => '{}'])),
        ]);

        try {
            app(SketchfabClient::class)->download($uid, $this->cache);
            $this->fail('arsip berbahaya harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('path berbahaya', $e->getMessage());
        }
        $this->assertFileDoesNotExist(sys_get_temp_dir().DIRECTORY_SEPARATOR.'evil.gltf');

        $other = str_repeat('e', 32);
        Http::fake(["api.sketchfab.com/v3/models/{$other}/download" => Http::response(['gltf' => ['url' => 'http://insecure.example.com/m.zip', 'size' => 1]])]);
        $this->expectException(RuntimeException::class);
        app(SketchfabClient::class)->download($other, $this->cache);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
