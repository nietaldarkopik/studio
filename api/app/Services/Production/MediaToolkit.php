<?php

namespace App\Services\Production;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Pembungkus ffmpeg/ffprobe. Semua argumen dikirim sebagai array (tanpa shell).
 */
class MediaToolkit
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const FPS = 30;

    /**
     * @param  array<int, string>  $command
     */
    public function run(array $command, int $timeout = 600, ?string $cwd = null): string
    {
        $process = new Process($command, $cwd, null, null, $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $tail = trim(mb_substr($process->getErrorOutput(), -600));

            throw new RuntimeException('Perintah media gagal: '.basename($command[0]).($tail !== '' ? ' — '.$tail : ''));
        }

        return $process->getOutput().$process->getErrorOutput();
    }

    public function ffmpeg(): string
    {
        return (string) config('services.production.ffmpeg_path', 'ffmpeg');
    }

    public function ffprobe(): string
    {
        return (string) config('services.production.ffprobe_path', 'ffprobe');
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(string $path): array
    {
        $json = $this->run([
            $this->ffprobe(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ], 60);

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function duration(string $path): float
    {
        $probe = $this->probe($path);

        return (float) ($probe['format']['duration'] ?? 0);
    }

    /**
     * Susun klip adegan: PNG sequence -> 1080x1920 @30fps (Lanczos + unsharp).
     */
    public function sceneClip(string $framesDir, int $renderFps, float $duration, string $output): void
    {
        $this->run([
            $this->ffmpeg(), '-y', '-loglevel', 'error',
            '-framerate', (string) $renderFps, '-start_number', '1', '-i', $framesDir.DIRECTORY_SEPARATOR.'%04d.png',
            '-vf', sprintf(
                'scale=%d:%d:flags=lanczos,unsharp=5:5:0.6,minterpolate=fps=%d:mi_mode=blend,tpad=stop_mode=clone:stop_duration=%.3f,trim=duration=%.3f,setpts=PTS-STARTPTS',
                self::WIDTH, self::HEIGHT, self::FPS, max(2.0, $duration), $duration,
            ),
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p', '-an', $output,
        ], 900);
    }

    /**
     * @param  array<int, string>  $clips
     */
    public function concatVideos(array $clips, string $output): void
    {
        $list = $output.'.txt';
        file_put_contents($list, implode("\n", array_map(
            fn (string $clip) => "file '".str_replace("'", "'\\''", str_replace('\\', '/', $clip))."'",
            $clips,
        ))."\n");

        try {
            $this->run([
                $this->ffmpeg(), '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $output,
            ], 600);
        } finally {
            @unlink($list);
        }
    }

    /**
     * Gabungkan audio per adegan; tiap adegan dipadatkan dengan senyap hingga durasi adegan.
     *
     * @param  array<int, string>  $audioFiles
     * @param  array<int, float>  $sceneDurations
     */
    public function narrationTrack(array $audioFiles, array $sceneDurations, string $output): void
    {
        $cmd = [$this->ffmpeg(), '-y', '-loglevel', 'error'];
        $filters = [];
        foreach ($audioFiles as $i => $file) {
            $cmd[] = '-i';
            $cmd[] = $file;
            $filters[] = sprintf('[%d:a]aresample=44100,aformat=channel_layouts=mono,apad=whole_dur=%.3f[a%d]', $i, $sceneDurations[$i], $i);
        }
        $labels = implode('', array_map(fn ($i) => "[a{$i}]", array_keys($audioFiles)));
        $filters[] = $labels.'concat=n='.count($audioFiles).':v=0:a=1[out]';

        array_push($cmd, '-filter_complex', implode(';', $filters), '-map', '[out]', '-ar', '44100', '-ac', '1', $output);
        $this->run($cmd, 300);
    }

    /**
     * Render akhir: video + narasi (100%) + BGM (-22 dB) + caption SRT + watermark.
     *
     * @param  array{bgm: ?string, logo: ?string, srt: string, total: float}  $opts
     */
    public function finalize(string $silentVideo, string $narration, string $output, array $opts, string $cwd): void
    {
        $cmd = [$this->ffmpeg(), '-y', '-loglevel', 'error', '-i', $silentVideo, '-i', $narration];
        $inputs = 2;

        $bgmIndex = null;
        if ($opts['bgm'] !== null) {
            array_push($cmd, '-stream_loop', '-1', '-i', $opts['bgm']);
            $bgmIndex = $inputs++;
        } else {
            // BGM sintetis (ambient gelap, bebas hak cipta): dua nada rendah + tremolo.
            array_push($cmd, '-f', 'lavfi', '-i', 'sine=frequency=82.4:sample_rate=44100');
            $bgmIndex = $inputs++;
            array_push($cmd, '-f', 'lavfi', '-i', 'sine=frequency=123.5:sample_rate=44100');
            $inputs++;
        }

        $logoIndex = null;
        if ($opts['logo'] !== null) {
            array_push($cmd, '-i', $opts['logo']);
            $logoIndex = $inputs++;
        }

        $video = "[0:v]subtitles={$this->relative($opts['srt'], $cwd)}:original_size=".self::WIDTH.'x'.self::HEIGHT
            .":force_style='FontName=Segoe UI,Bold=1,FontSize=12,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=1,Shadow=0.4,Alignment=2,MarginV=62'[v0]";
        $filters = [$video];
        $last = 'v0';
        if ($logoIndex !== null) {
            $filters[] = "[{$logoIndex}:v]scale=150:-1,format=rgba,colorchannelmixer=aa=0.85[lg]";
            $filters[] = "[{$last}][lg]overlay=W-w-40:60[v1]";
            $last = 'v1';
        }

        if ($opts['bgm'] !== null) {
            $filters[] = "[{$bgmIndex}:a]volume=-22dB,atrim=duration=".sprintf('%.3f', $opts['total'])."[bgm]";
        } else {
            $second = $bgmIndex + 1;
            $filters[] = "[{$bgmIndex}:a][{$second}:a]amix=inputs=2:normalize=0,tremolo=f=0.25:d=0.6,volume=-22dB,atrim=duration=".sprintf('%.3f', $opts['total']).'[bgm]';
        }
        $filters[] = '[1:a][bgm]amix=inputs=2:duration=first:normalize=0[aout]';

        array_push(
            $cmd,
            '-filter_complex', implode(';', $filters),
            '-map', "[{$last}]", '-map', '[aout]',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', '-shortest', $output,
        );

        $this->run($cmd, 1800, $cwd);
    }

    /**
     * Peta kontak 3x2 dari video untuk tinjauan visual.
     */
    public function contactSheet(string $video, string $output): void
    {
        $this->run([
            $this->ffmpeg(), '-y', '-loglevel', 'error', '-i', $video,
            '-vf', 'fps=6/'.max(1, (int) ceil($this->duration($video))).',scale=270:-1,tile=3x2',
            '-frames:v', '1', $output,
        ], 120);
    }

    /**
     * Rata-rata kecerahan (0-255) dan rentang antar-frame sampel; mendeteksi video hitam/beku.
     *
     * @return array{min: float, max: float, mean: float}
     */
    public function brightnessStats(string $video): array
    {
        $out = $this->run([
            $this->ffmpeg(), '-hide_banner', '-nostats', '-i', $video,
            '-vf', 'fps=1,signalstats,metadata=print:key=lavfi.signalstats.YAVG', '-an', '-f', 'null', '-',
        ], 300);

        preg_match_all('/lavfi\.signalstats\.YAVG=([0-9.]+)/', $out, $matches);
        $values = array_map('floatval', $matches[1] ?? []);
        if ($values === []) {
            return ['min' => 0.0, 'max' => 0.0, 'mean' => 0.0];
        }

        return ['min' => min($values), 'max' => max($values), 'mean' => array_sum($values) / count($values)];
    }

    /**
     * Volume rata-rata audio dalam dB (mis. -23.1); null bila tidak terdeteksi.
     */
    public function meanVolume(string $video): ?float
    {
        $out = $this->run([
            $this->ffmpeg(), '-hide_banner', '-nostats', '-i', $video, '-vn', '-af', 'volumedetect', '-f', 'null', '-',
        ], 120);

        return preg_match('/mean_volume:\s*(-?[0-9.]+) dB/', $out, $m) ? (float) $m[1] : null;
    }

    /**
     * Ubah SRT path menjadi path relatif ke cwd (aman bagi filter ffmpeg di Windows).
     */
    private function relative(string $path, string $cwd): string
    {
        $path = str_replace('\\', '/', $path);
        $cwd = rtrim(str_replace('\\', '/', $cwd), '/').'/';

        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : basename($path);
    }
}
