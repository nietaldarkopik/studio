<?php

namespace Tests\Feature;

use App\Services\PolyPizza\PolyPizzaClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PolyPizzaClientTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio_polypizza_test';
        File::deleteDirectory($this->cache);
        config(['services.polypizza.key' => 'pp-key', 'services.polypizza.allowed_licenses' => 'cc0,by']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cache);
        parent::tearDown();
    }

    public function test_find_picks_first_allowed_license_and_sends_auth_header(): void
    {
        Http::fake(['api.poly.pizza/*' => Http::response(['results' => [
            ['ID' => 'nc', 'Title' => 'NC', 'Download' => 'https://static.poly.pizza/nc.glb', 'Licence' => 'CC-BY-NC 3.0', 'Creator' => ['Username' => 'A']],
            ['ID' => 'ok1', 'Title' => 'Candi', 'Download' => 'https://static.poly.pizza/ok1.glb', 'Licence' => 'CC-BY 3.0', 'Creator' => ['Username' => 'Budi']],
        ]])]);

        $found = app(PolyPizzaClient::class)->find('temple');

        $this->assertSame('ok1', $found['uid']);
        $this->assertSame('by', $found['license_slug']);
        $this->assertSame('Budi', $found['author']);
        $this->assertSame('https://poly.pizza/m/ok1', $found['url']);
        Http::assertSent(fn ($r) => $r->hasHeader('x-auth-token', 'pp-key') && str_contains($r->url(), '/search/temple'));
    }

    public function test_cc0_is_accepted_and_unconfigured_never_calls_the_api(): void
    {
        Http::fake(['api.poly.pizza/*' => Http::response(['results' => [
            ['id' => 'z', 'title' => 'Pohon', 'download' => 'https://static.poly.pizza/z.glb', 'license' => 'CC0 1.0', 'creator' => ['username' => 'C']],
        ]])]);
        $this->assertSame('cc0', app(PolyPizzaClient::class)->find('tree')['license_slug']);

        config(['services.polypizza.key' => null]);
        Http::fake();
        $this->assertNull(app(PolyPizzaClient::class)->find('tree'));
        Http::assertNothingSent();
    }

    public function test_download_requires_a_real_glb_and_caches(): void
    {
        $found = ['uid' => 'ok1', 'download' => 'https://static.poly.pizza/ok1.glb'];
        Http::fake(['static.poly.pizza/*' => Http::response('glTF'.str_repeat("\0", 64))]);

        $client = app(PolyPizzaClient::class);
        $path = $client->download($found, $this->cache);
        $this->assertFileExists($path);

        Http::fake()->preventStrayRequests();
        $this->assertSame($path, $client->download($found, $this->cache)); // dari cache

        Http::fake(['static.poly.pizza/*' => Http::response('<html>bukan glb</html>')]);
        $this->expectException(RuntimeException::class);
        $client->download(['uid' => 'bad', 'download' => 'https://static.poly.pizza/bad.glb'], $this->cache);
    }
}
