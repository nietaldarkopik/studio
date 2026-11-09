<?php

namespace App\Services\Research;

use App\Models\Project;
use App\Models\ResearchSource;
use App\Services\AI\NineRouterClient;
use App\Services\Search\SearchClient;
use Throwable;

/**
 * Riset otomatis oleh AI: cari sumber, pilih, simpan, dan (opsional) verifikasi terhadap naskah/topik.
 */
class ResearchService
{
    public function __construct(
        private readonly NineRouterClient $ai,
        private readonly SearchClient $search,
    ) {
    }

    /**
     * Cari dan simpan sumber (belum terverifikasi) hingga minimal $wantDomains domain berbeda.
     *
     * @param  array<int, string>  $queries
     * @return int jumlah sumber baru
     */
    public function collect(Project $project, int $userId, array $queries, int $wantDomains = 3): int
    {
        $created = 0;

        foreach ($queries as $query) {
            if ($this->distinctDomains($project) >= $wantDomains) {
                break;
            }

            try {
                $results = $this->search->search($query);
                if ($results === []) {
                    continue;
                }

                $curated = $this->ai->curateResearchCandidates([
                    'project_title' => $project->title,
                    'topic' => $project->topic,
                    'query' => $query,
                    'results' => $results,
                ]);
            } catch (Throwable) {
                continue; // satu kata kunci gagal tidak menghentikan yang lain
            }

            foreach ($curated['candidates'] as $candidate) {
                if ($project->researchSources()->where('url', $candidate['url'])->exists()) {
                    continue;
                }

                $project->researchSources()->create([
                    'created_by' => $userId,
                    'title' => mb_substr((string) $candidate['title'], 0, 200),
                    'url' => mb_substr((string) $candidate['url'], 0, 500),
                    'publisher' => $candidate['publisher'] ?? null,
                    'published_at' => $candidate['published_at'] ?? null,
                    'key_findings' => $candidate['key_findings'] ?? null,
                    'reliability_note' => trim(($candidate['reliability_note'] ?? '').' [Dicari otomatis oleh AI]'),
                    'verified' => false,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return array<int, string>
     */
    public function queriesFor(Project $project): array
    {
        try {
            return $this->ai->generateResearchQueries($project->title, $project->topic);
        } catch (Throwable) {
            return [$project->topic];
        }
    }

    /**
     * AI menilai apakah temuan tiap sumber mendukung topik/naskah; sumber yang didukung ditandai
     * terverifikasi (dengan catatan). Ini verifikasi berbasis isi ringkasan, bukan kunjungan manual.
     *
     * @return array{verified: int, rejected: int}
     */
    public function verify(Project $project): array
    {
        $sources = $project->researchSources()->get()->filter(fn (ResearchSource $s) => filled($s->key_findings))->values();
        if ($sources->isEmpty()) {
            return ['verified' => 0, 'rejected' => 0];
        }

        $verdicts = $this->ai->verifySources(
            $project->topic,
            (string) ($project->script?->narration ?? ''),
            $sources->map(fn (ResearchSource $s) => ['id' => $s->id, 'title' => $s->title, 'findings' => (string) $s->key_findings])->all(),
        );

        $verified = 0;
        $rejected = 0;
        foreach ($sources as $source) {
            $verdict = $verdicts[$source->id] ?? null;
            if ($verdict === null) {
                continue;
            }

            $note = trim(preg_replace('/\s*\[(Diverifikasi|Ditolak) AI[^\]]*\]/u', '', (string) $source->reliability_note) ?? '');
            $stamp = $verdict['supported'] ? '[Diverifikasi AI' : '[Ditolak AI';
            $source->update([
                'verified' => $verdict['supported'],
                'reliability_note' => trim($note.' '.$stamp.': '.$verdict['note'].']'),
            ]);
            $verdict['supported'] ? $verified++ : $rejected++;
        }

        return ['verified' => $verified, 'rejected' => $rejected];
    }

    public function distinctDomains(Project $project): int
    {
        $hosts = [];
        foreach ($project->researchSources()->pluck('url') as $url) {
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url((string) $url, PHP_URL_HOST))) ?? '';
            $hosts[] = implode('.', array_slice(explode('.', $host), -2));
        }

        return count(array_unique(array_filter($hosts)));
    }
}
