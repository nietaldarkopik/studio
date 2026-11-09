<?php

namespace App\Console\Commands;

use App\Services\PolyPizza\PolyPizzaClient;
use App\Services\Sketchfab\SketchfabClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('studio:check-assets {query=temple : Kata kunci uji pencarian model} {--download : Unduh juga model pertama yang ditemukan (uji penuh)}')]
#[Description('Periksa konfigurasi sumber model 3D (Poly Pizza, Sketchfab) dengan pencarian nyata; key tidak pernah ditampilkan')]
class StudioCheckAssets extends Command
{
    public function handle(PolyPizzaClient $polyPizza, SketchfabClient $sketchfab): int
    {
        $query = (string) $this->argument('query');
        $cache = storage_path('app/production/assets_check');
        $ok = true;

        foreach ([
            'Poly Pizza' => [$polyPizza, 'POLYPIZZA_API_KEY', fn () => $polyPizza->find($query), fn (array $f) => $polyPizza->download($f, $cache.DIRECTORY_SEPARATOR.'polypizza')],
            'Sketchfab' => [$sketchfab, 'SKETCHFAB_API_TOKEN', fn () => $sketchfab->find($query), fn (array $f) => $sketchfab->download($f['uid'], $cache.DIRECTORY_SEPARATOR.'sketchfab')],
        ] as $name => [$client, $envName, $find, $download]) {
            if (! $client->isConfigured()) {
                $this->components->warn("{$name}: {$envName} belum diisi di api/.env (dilewati).");

                continue;
            }

            try {
                $found = $find();
            } catch (Throwable $exception) {
                $this->components->error("{$name}: pencarian gagal ({$exception->getMessage()}).");
                $ok = false;

                continue;
            }

            if ($found === null) {
                $reason = $client instanceof SketchfabClient ? $client->lastError() : null;
                $this->components->error("{$name}: ".($reason ?? "key terbaca tetapi tidak ada hasil berlisensi yang diizinkan untuk \"{$query}\" (key salah, kuota habis, atau tidak ada hasil CC0/CC-BY)."));
                $ok = false;

                continue;
            }

            $this->components->info("{$name}: OK — \"{$found['name']}\" oleh {$found['author']} ({$found['license']}) {$found['url']}");

            if ($this->option('download')) {
                try {
                    $path = $download($found);
                    $this->components->twoColumnDetail('  Unduh', basename($path).' ('.round(filesize($path) / 1024).' KB)');
                } catch (Throwable $exception) {
                    $this->components->error("{$name}: unduhan gagal ({$exception->getMessage()}).");
                    $ok = false;
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
