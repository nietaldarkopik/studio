<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NineRouterClient
{
    /** @var array<int, string> */
    private const SOURCE_FIELDS = ['title', 'publisher', 'published_at', 'key_findings', 'reliability_note'];

    /**
     * @return array{narration: string, scenes: array<int, array<string, mixed>>, model: string, word_count: int}
     */
    public function generateScriptDraft(string $topic, string $format): array
    {
        $content = $this->complete(
            $this->systemPrompt($format),
            "Topik proyek: {$topic}\n\nTulis satu draf narasi sesuai format yang dipilih.",
            '9Router mengembalikan respons tanpa narasi yang valid.',
        );

        $draft = $this->parseDraft($content);
        if ($draft['narration'] === '') {
            throw new RuntimeException('9Router mengembalikan respons tanpa narasi yang valid.', 502);
        }

        preg_match_all('/\S+/u', $draft['narration'], $words);

        return [
            'narration' => $draft['narration'],
            'scenes' => $draft['scenes'],
            'model' => $this->model(),
            'word_count' => count($words[0]),
        ];
    }

    /**
     * Rancang prompt visual terstruktur untuk satu adegan storyboard.
     *
     * @param  array<string, mixed>  $context
     * @return array{title: string, positive: string, negative: ?string, style: ?string, camera: ?string, lighting: ?string, mood: ?string, notes: ?string, model: string}
     */
    public function generatePromptDraft(array $context): array
    {
        $content = $this->complete(
            $this->promptSystemPrompt(),
            $this->promptUserPrompt($context),
            '9Router mengembalikan respons prompt yang tidak valid.',
        );

        $decoded = $this->decodeJsonObject($content) ?? [];
        $positive = $this->optionalText($decoded['positive'] ?? null);

        if ($positive === null) {
            throw new RuntimeException('9Router mengembalikan respons prompt yang tidak valid.', 502);
        }

        return [
            'title' => $this->clamp($this->optionalText($decoded['title'] ?? null), 120),
            'positive' => $this->clamp($positive, 4000),
            'negative' => $this->clamp($this->optionalText($decoded['negative'] ?? null), 4000),
            'style' => $this->clamp($this->optionalText($decoded['style'] ?? null), 160),
            'camera' => $this->clamp($this->optionalText($decoded['camera'] ?? null), 160),
            'lighting' => $this->clamp($this->optionalText($decoded['lighting'] ?? null), 160),
            'mood' => $this->clamp($this->optionalText($decoded['mood'] ?? null), 120),
            'notes' => $this->clamp($this->optionalText($decoded['notes'] ?? null), 2000),
            'model' => $this->model(),
        ];
    }

    /**
     * Rancang prompt visual untuk semua adegan storyboard dalam satu permintaan.
     *
     * @param  array<string, mixed>  $context
     * @return array{prompts: array<int, array<string, mixed>>, model: string}
     */
    public function generatePromptBatchDrafts(array $context): array
    {
        $scenes = is_array($context['scenes'] ?? null) ? $context['scenes'] : [];
        if ($scenes === []) {
            throw new RuntimeException('9Router mengembalikan respons prompt yang tidak valid.', 502);
        }

        $content = $this->complete(
            $this->promptBatchSystemPrompt(),
            $this->promptBatchUserPrompt($context, $scenes),
            '9Router mengembalikan respons prompt yang tidak valid.',
        );

        $decoded = $this->decodeJsonObject($content) ?? [];
        $allowed = [];
        foreach ($scenes as $scene) {
            $allowed[(int) ($scene['scene_id'] ?? 0)] = true;
        }

        $prompts = [];
        foreach ((array) ($decoded['prompts'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sceneId = (int) ($entry['scene_id'] ?? 0);
            $positive = $this->optionalText($entry['positive'] ?? null);

            if ($positive === null || ! isset($allowed[$sceneId])) {
                continue;
            }

            $prompts[] = [
                'scene_id' => $sceneId,
                'title' => $this->clamp($this->optionalText($entry['title'] ?? null), 120),
                'positive' => $this->clamp($positive, 4000),
                'negative' => $this->clamp($this->optionalText($entry['negative'] ?? null), 4000),
                'style' => $this->clamp($this->optionalText($entry['style'] ?? null), 160),
                'camera' => $this->clamp($this->optionalText($entry['camera'] ?? null), 160),
                'lighting' => $this->clamp($this->optionalText($entry['lighting'] ?? null), 160),
                'mood' => $this->clamp($this->optionalText($entry['mood'] ?? null), 120),
                'notes' => $this->clamp($this->optionalText($entry['notes'] ?? null), 2000),
            ];
        }

        if ($prompts === []) {
            throw new RuntimeException('9Router mengembalikan respons prompt yang tidak valid.', 502);
        }

        return ['prompts' => $prompts, 'model' => $this->model()];
    }

    /**
     * Isi otomatis seluruh field satu sumber riset berdasarkan teks halaman (bila tersedia).
     *
     * @param  array<string, mixed>  $context
     * @return array{title: ?string, publisher: ?string, published_at: ?string, key_findings: string, reliability_note: ?string, inferred_fields: array<int, string>, model: string}
     */
    public function fillResearchSource(array $context): array
    {
        $content = $this->complete(
            $this->sourceFillSystemPrompt(),
            $this->sourceFillUserPrompt($context),
            '9Router mengembalikan respons riset yang tidak valid.',
        );

        $decoded = $this->decodeJsonObject($content) ?? [];
        $findings = $this->optionalText($decoded['key_findings'] ?? null);

        if ($findings === null) {
            throw new RuntimeException('9Router mengembalikan respons riset yang tidak valid.', 502);
        }

        $inferred = [];
        foreach ((array) ($decoded['inferred_fields'] ?? []) as $field) {
            if (is_string($field) && in_array($field, self::SOURCE_FIELDS, true) && ! in_array($field, $inferred, true)) {
                $inferred[] = $field;
            }
        }

        return [
            'title' => $this->clamp($this->optionalText($decoded['title'] ?? null), 200),
            'publisher' => $this->clamp($this->optionalText($decoded['publisher'] ?? null), 120),
            'published_at' => $this->date($decoded['published_at'] ?? null),
            'key_findings' => $this->clamp($findings, 5000),
            'reliability_note' => $this->clamp($this->optionalText($decoded['reliability_note'] ?? null), 2000),
            'inferred_fields' => $inferred,
            'model' => $this->model(),
        ];
    }

    /**
     * Pilih dan ringkas kandidat sumber dari hasil mesin pencari.
     *
     * @param  array<string, mixed>  $context
     * @return array{candidates: array<int, array<string, mixed>>, model: string}
     */
    public function curateResearchCandidates(array $context): array
    {
        $results = is_array($context['results'] ?? null) ? array_values($context['results']) : [];

        if ($results === []) {
            throw new RuntimeException('Tavily tidak menemukan sumber untuk kata kunci ini.', 502);
        }

        $content = $this->complete(
            $this->candidateSystemPrompt(),
            $this->candidateUserPrompt($context, $results),
            '9Router mengembalikan respons riset yang tidak valid.',
        );

        $decoded = $this->decodeJsonObject($content) ?? [];
        $known = [];
        foreach ($results as $result) {
            $url = is_array($result) ? ($result['url'] ?? null) : null;
            if (is_string($url)) {
                $known[$url] = $result;
            }
        }

        $candidates = [];
        foreach ((array) ($decoded['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $url = is_string($candidate['url'] ?? null) ? trim($candidate['url']) : '';
            if ($url === '' || ! isset($known[$url])) {
                continue;
            }

            $source = $known[$url];
            $findings = $this->optionalText($candidate['key_findings'] ?? null);
            if ($findings === null) {
                continue;
            }

            $candidates[] = [
                'title' => $this->clamp($this->optionalText($candidate['title'] ?? null), 200) ?? $source['title'],
                'url' => $url,
                'publisher' => $this->clamp($this->optionalText($candidate['publisher'] ?? null), 120),
                'published_at' => $this->date($candidate['published_at'] ?? null) ?? ($source['published_at'] ?? null),
                'snippet' => $this->clamp($this->optionalText($source['snippet'] ?? null), 600) ?? '',
                'key_findings' => $this->clamp($findings, 5000),
                'reliability_note' => $this->clamp($this->optionalText($candidate['reliability_note'] ?? null), 2000),
                'reason' => $this->clamp($this->optionalText($candidate['reason'] ?? null), 400),
            ];
        }

        if ($candidates === []) {
            throw new RuntimeException('9Router mengembalikan respons riset yang tidak valid.', 502);
        }

        return ['candidates' => $candidates, 'model' => $this->model()];
    }

    private function complete(string $systemPrompt, string $userPrompt, string $emptyMessage): string
    {
        $baseUrl = rtrim((string) config('services.nine_router.base_url'), '/');
        $model = $this->model();
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
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
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
            throw new RuntimeException($emptyMessage, 502);
        }

        return trim($content);
    }

    private function model(): string
    {
        return (string) config('services.nine_router.model');
    }

    private function clamp(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
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

    private function promptSystemPrompt(): string
    {
        return implode("\n", [
            'Anda merancang prompt visual terstruktur untuk produksi video pendek berbahasa Indonesia.',
            'Berdasarkan satu adegan storyboard, tulis satu prompt sinematik yang konkret dan dapat dirender.',
            'Isi positive dengan subjek, aksi, komposisi, latar, dan gaya. Isi negative dengan artefak yang harus dihindari.',
            'Field style, camera, lighting, dan mood harus singkat (maksimal beberapa kata).',
            'Gunakan bahasa Inggris untuk positive, negative, style, camera, dan lighting; gunakan bahasa Indonesia untuk title dan mood.',
            'Jangan menyertakan nama merek, karakter berhak cipta, atau orang nyata tertentu.',
            'Balas hanya dengan satu objek JSON valid tanpa penjelasan, markdown, atau code fence, dengan bentuk:',
            '{"title":"judul singkat","positive":"...","negative":"...","style":"...","camera":"...","lighting":"...","mood":"...","notes":"..."}',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function promptUserPrompt(array $context): string
    {
        $lines = [
            'Judul proyek: '.$this->contextValue($context, 'project_title'),
            'Topik proyek: '.$this->contextValue($context, 'topic'),
            'Judul adegan: '.$this->contextValue($context, 'scene_title'),
            'Narasi adegan: '.$this->contextValue($context, 'narration'),
            'Deskripsi visual adegan: '.$this->contextValue($context, 'visual_description'),
            'Arahan kamera adegan: '.$this->contextValue($context, 'camera_direction'),
        ];

        return implode("\n", $lines)."\n\nRancang satu prompt visual terstruktur untuk adegan ini.";
    }

    private function promptBatchSystemPrompt(): string
    {
        return implode("\n", [
            'Anda merancang prompt visual terstruktur untuk produksi video pendek berbahasa Indonesia.',
            'Anda menerima daftar adegan storyboard. Tulis satu prompt sinematik yang konkret dan dapat dirender untuk SETIAP adegan.',
            'Jaga kesinambungan gaya antar adegan agar hasilnya terasa satu video utuh.',
            'Isi positive dengan subjek, aksi, komposisi, latar, dan gaya. Isi negative dengan artefak yang harus dihindari.',
            'Field style, camera, lighting, dan mood harus singkat (maksimal beberapa kata).',
            'Gunakan bahasa Inggris untuk positive, negative, style, camera, dan lighting; gunakan bahasa Indonesia untuk title dan mood.',
            'Jangan menyertakan nama merek, karakter berhak cipta, atau orang nyata tertentu.',
            'Sertakan setiap scene_id yang diberikan tepat satu kali tanpa mengubah id, dan jangan menambah adegan baru.',
            'Balas hanya dengan satu objek JSON valid tanpa penjelasan, markdown, atau code fence, dengan bentuk:',
            '{"prompts":[{"scene_id":1,"title":"judul singkat","positive":"...","negative":"...","style":"...","camera":"...","lighting":"...","mood":"...","notes":"..."}]}',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function promptBatchUserPrompt(array $context, array $scenes): string
    {
        $lines = [
            'Judul proyek: '.$this->contextValue($context, 'project_title'),
            'Topik proyek: '.$this->contextValue($context, 'topic'),
            '',
            'Daftar adegan:',
        ];

        foreach ($scenes as $scene) {
            $lines[] = sprintf(
                '- scene_id %d | judul: %s | narasi: %s | visual: %s | kamera: %s',
                (int) ($scene['scene_id'] ?? 0),
                $this->contextValue($scene, 'title'),
                $this->contextValue($scene, 'narration'),
                $this->contextValue($scene, 'visual_description'),
                $this->contextValue($scene, 'camera_direction'),
            );
        }

        $lines[] = '';
        $lines[] = 'Rancang satu prompt visual terstruktur untuk setiap adegan di atas.';

        return implode("\n", $lines);
    }

    private function sourceFillSystemPrompt(): string
    {
        return implode("\n", [
            'Anda membantu mengisi metadata dan temuan kunci untuk satu sumber riset pada produksi video pendek berbahasa Indonesia.',
            'Anda TIDAK memiliki akses internet. Anda hanya boleh merangkum dari "Isi halaman" yang disediakan.',
            'Jika "Isi halaman" berisi (tidak tersedia), jangan mengarang kutipan, angka, tanggal, atau nama lembaga. Isi hanya hal yang benar-benar tercermin dari judul dan URL yang diberikan, dan masukkan field apa pun yang Anda perkirakan ke dalam inferred_fields.',
            'Title adalah judul halaman yang ringkas. Publisher adalah nama penerbit atau situs. Published_at adalah perkiraan tanggal terbit format YYYY-MM-DD bila dapat disimpulkan, jika tidak biarkan kosong.',
            'Tulis key_findings sebagai daftar poin ("- ...") yang merangkum temuan kunci relevan untuk topik proyek, berdasarkan isi halaman bila ada.',
            'Tulis reliability_note sebagai penilaian awal singkat tentang jenis sumber dan hal yang perlu dicek pengguna.',
            'Selalu cantumkan pada inferred_fields nama field yang nilainya Anda perkirakan atau inferensi, bukan yang tercantum eksplisit di isi halaman. Pilihan sah: title, publisher, published_at, key_findings, reliability_note.',
            'Balas hanya dengan satu objek JSON valid tanpa penjelasan, markdown, atau code fence, dengan bentuk:',
            '{"title":"...","publisher":"...","published_at":"YYYY-MM-DD","key_findings":"- poin ...\n- poin ...","reliability_note":"...","inferred_fields":["publisher"]}',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sourceFillUserPrompt(array $context): string
    {
        $page = $this->optionalText($context['page_text'] ?? null) ?? '(tidak tersedia)';

        $lines = [
            'Judul proyek: '.$this->contextValue($context, 'project_title'),
            'Topik proyek: '.$this->contextValue($context, 'topic'),
            'Judul saat ini: '.$this->contextValue($context, 'title'),
            'URL sumber: '.$this->contextValue($context, 'url'),
            'Penerbit saat ini: '.$this->contextValue($context, 'publisher'),
            'Tanggal terbit saat ini: '.$this->contextValue($context, 'published_at'),
            '',
            'Isi halaman:',
            $page,
        ];

        return implode("\n", $lines)."\n\nIsi metadata dan temuan kunci untuk sumber ini.";
    }

    private function candidateSystemPrompt(): string
    {
        return implode("\n", [
            'Anda membantu memilih sumber riset paling relevan untuk produksi video pendek berbahasa Indonesia.',
            'Anda menerima daftar hasil mesin pencari. Anda HANYA boleh memilih dari daftar itu dan tidak boleh menciptakan URL atau sumber baru.',
            'Urutkan pilihan dari yang paling relevan dan kredibel. Jangan mengarang kutipan, angka, atau tanggal; rangkum hanya dari judul dan cuplikan yang tersedia.',
            'Kolom reason menjelaskan singkat mengapa sumber itu relevan untuk topik proyek.',
            'Tulis key_findings sebagai daftar poin ("- ...") berisi hal yang perlu diperiksa dari sumber itu untuk topik proyek.',
            'Balas hanya dengan satu objek JSON valid tanpa penjelasan, markdown, atau code fence, dengan bentuk:',
            '{"candidates":[{"url":"https://contoh.id/artikel","title":"judul sumber","publisher":"nama penerbit","published_at":"YYYY-MM-DD","key_findings":"- poin ...\n- poin ...","reliability_note":"...","reason":"..."}]}',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $results
     */
    private function candidateUserPrompt(array $context, array $results): string
    {
        $lines = [
            'Judul proyek: '.$this->contextValue($context, 'project_title'),
            'Topik proyek: '.$this->contextValue($context, 'topic'),
            'Kata kunci pencarian: '.$this->contextValue($context, 'query'),
            '',
            'Hasil mesin pencari:',
        ];

        foreach ($results as $result) {
            $lines[] = sprintf(
                '- URL: %s | judul: %s | penerbit: %s | tanggal: %s | cuplikan: %s',
                $this->contextValue($result, 'url'),
                $this->contextValue($result, 'title'),
                $this->contextValue($result, 'publisher'),
                $this->contextValue($result, 'published_at'),
                $this->contextValue($result, 'snippet'),
            );
        }

        $lines[] = '';
        $lines[] = 'Pilih sumber paling relevan dari daftar di atas untuk topik proyek.';

        return implode("\n", $lines);
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function contextValue(array $context, string $key): string
    {
        $value = $context[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : '(tidak tersedia)';
    }
}
