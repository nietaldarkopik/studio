<?php

namespace App\Services\Production;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Menjalankan Blender secara headless dengan skrip render terbatas (allowlist):
 * hanya resources/blender/render_video.py dengan spec.json yang dihasilkan backend.
 */
class BlenderRenderer
{
    public function executable(): string
    {
        return (string) config('services.production.blender_path');
    }

    public function isAvailable(): bool
    {
        $path = $this->executable();

        return $path !== '' && is_file($path);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function render(array $spec, string $specPath): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Blender tidak ditemukan di BLENDER_PATH.', 503);
        }

        file_put_contents($specPath, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $process = new Process([
            $this->executable(),
            '-b', '--factory-startup',
            '-P', resource_path('blender/render_video.py'),
            '--', $specPath,
        ], null, null, null, (float) config('services.production.render_timeout', 14400));
        $process->setIdleTimeout(900);

        $process->run();

        if (! str_contains($process->getOutput(), 'RENDER_DONE')) {
            $tail = trim(mb_substr($process->getOutput().$process->getErrorOutput(), -500));

            throw new RuntimeException('Render Blender gagal'.($tail !== '' ? ': '.$tail : '.'), 500);
        }
    }

    /**
     * @return int jumlah frame PNG pada satu direktori adegan
     */
    public function countFrames(string $sceneDir): int
    {
        return count(glob(rtrim($sceneDir, '/\\').DIRECTORY_SEPARATOR.'*.png') ?: []);
    }
}
