<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NineRouterClient
{
    /**
     * @return array{narration: string, scenes: array<int, array<string, mixed>>, model: string, word_count: int}
     */
    public function generateScriptDraft(string $topic, string $format): array
    {
        $baseUrl = rtrim((string) config('services.nine_router.base_url'), '/');
        $model = (string) config('services.nine_router.model');
        $apiKey = $this->apiKey();

        if (! $apiKey || ! filter_var($baseUrl, FILTER_VALIDATE_URL) || $model === '') {
            throw new RuntimeException('9Router belum dikonfigurasi dengan lengkap.', 503);
        }

        $timeout = max(1, (int) config('services.nine_router.timeout', 600));

        try {
            $response = Http::connectTimeout(5)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->withToken($apiKey)
                ->acceptJson()
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'temperature' => 0.7,
                    'stream' => false,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt($format),
                        ],
                        [
                            'role' => 'user',
                            'content' => "Topik proyek: {$topic}\n\nTulis satu draf narasi sesuai format yang dipilih.",
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('9Router tidak merespons dalam batas waktu.', 503);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                $this->providerErrorMessage($response),
                $response->serverError() ? 503 : 502,
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('9Router mengembalikan respons tanpa narasi yang valid.', 502);
        }

        $draft = $this->parseDraft(trim($content));
        if ($draft['narration'] === '') {
            throw new RuntimeException('9Router mengembalikan respons tanpa narasi yang valid.', 502);
        }

        preg_match_all('/\S+/u', $draft['narration'], $words);

        return [
            'narration' => $draft['narration'],
            'scenes' => $draft['scenes'],
            'model' => $model,
            'word_count' => count($words[0]),
        ];
    }

    /**
     * @return array{narration: string, scenes: array<int, array<string, mixed>>}
     */
    private function parseDraft(string $content): array
    {
        $decoded = $this->decodeJsonObject($content);

        if ($decoded === null) {
            return ['narration' => $content, 'scenes' => []];
        }

        $scenes = [];
        foreach ((array) ($decoded['scenes'] ?? []) as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $title = trim((string) ($scene['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $duration = (int) ($scene['duration_seconds'] ?? 0);
            $scenes[] = [
                'title' => mb_substr($title, 0, 120),
                'narration' => $this->optionalText($scene['narration'] ?? null),
                'visual_description' => $this->optionalText($scene['visual_description'] ?? null),
                'camera_direction' => $this->optionalText($scene['camera_direction'] ?? null),
                'duration_seconds' => $duration >= 1 && $duration <= 60 ? $duration : 5,
            ];

            if (count($scenes) >= 20) {
                break;
            }
        }

        $narration = trim((string) ($decoded['narration'] ?? ''));
        if ($narration === '') {
            $narration = trim(implode(' ', array_filter(array_column($scenes, 'narration'))));
        }

        return ['narration' => $narration, 'scenes' => $scenes];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $content): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content;
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function optionalText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function providerErrorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        if (! is_string($message) || trim($message) === '') {
            $message = $response->json('message');
        }

        if (! is_string($message) || trim($message) === '') {
            return "9Router merespons dengan HTTP {$response->status()} tanpa detail pesan.";
        }

        $message = trim(strip_tags($message));
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/i', '[REDACTED]', $message) ?? $message;
        $message = preg_replace('/((?:api[-_ ]?key|token|secret)\s*[:=]\s*)[^\s,;]+/i', '$1[REDACTED]', $message) ?? $message;
        $message = trim($message);

        return $message === ''
            ? "9Router merespons dengan HTTP {$response->status()} tanpa detail pesan."
            : "9Router HTTP {$response->status()}: {$message}";
    }

    private function apiKey(): ?string
    {
        $configuredKey = trim((string) config('services.nine_router.api_key'));
        if ($configuredKey !== '') {
            return $configuredKey;
        }

        $keyFile = trim((string) config('services.nine_router.api_key_file'));
        if ($keyFile === '') {
            return null;
        }

        $path = base_path($keyFile);
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $fileKey = @file_get_contents($path);
        if (! is_string($fileKey) || trim($fileKey) === '') {
            return null;
        }

        return trim($fileKey);
    }

    private function systemPrompt(string $format): string
    {
        $formatGuidance = match ($format) {
            'chain_build' => 'Gunakan pola Rantai Bangun: hook yang kuat, rangkaian sebab-akibat yang makin menarik, lalu payoff yang menjawab hook.',
            'mystery_investigation' => 'Gunakan pola Misteri & Investigasi: ajukan kejanggalan, bangun petunjuk, ungkap penjelasan, lalu tutup dengan fakta paling mengejutkan.',
            'flag_banter' => 'Gunakan pola Debat Bendera: bandingkan dua sisi secara ringan dan berimbang, lalu akhiri dengan konteks yang informatif tanpa merendahkan kelompok mana pun.',
            default => throw new RuntimeException('Unsupported script format.', 422),
        };

        return implode("\n", [
            'Tulis naskah narasi YouTube Shorts berbahasa Indonesia yang orisinal dan mudah diucapkan, lalu pecah menjadi 4–8 adegan berurutan.',
            $formatGuidance,
            'Targetkan 95–160 kata untuk keseluruhan narasi, dengan kalimat ringkas dan satu alur yang utuh.',
            'Setiap adegan berisi potongan narasi, deskripsi visual, arahan kamera ringkas, dan duration_seconds berupa angka bulat 1–60. Total durasi adegan sekitar 30–55 detik.',
            'Topik proyek adalah bahan awal, bukan sumber terverifikasi. Jangan mengarang kutipan, sumber, angka, atau klaim spesifik yang tidak dapat dipastikan.',
            'Jika detail faktual belum pasti, gunakan bahasa berhati-hati. Naskah ini wajib diperiksa terhadap sedikitnya dua sumber independen sebelum produksi.',
            'Balas hanya dengan satu objek JSON valid tanpa penjelasan, markdown, atau code fence, dengan bentuk:',
            '{"narration":"narasi lengkap","scenes":[{"title":"judul adegan","narration":"potongan narasi","visual_description":"deskripsi visual","camera_direction":"arahan kamera","duration_seconds":5}]}',
        ]);
    }
}
