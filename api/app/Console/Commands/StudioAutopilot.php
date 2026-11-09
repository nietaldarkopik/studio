<?php

namespace App\Console\Commands;

use App\Jobs\RunProduction;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('studio:autopilot {--workspace= : ID workspace (default: workspace pertama)} {--theme= : Arahan tema opsional} {--format= : chain_build|mystery_investigation|flag_banter} {--publish : Upload ke YouTube otomatis bila QC lolos} {--sync : Jalankan di proses ini, bukan antrean} {--resume= : ID run yang dilanjutkan}')]
#[Description('Buat satu video penuh otomatis: tema, judul, riset, naskah, render, QC, lalu upload YouTube')]
class StudioAutopilot extends Command
{
    public function handle(ProductionPipeline $pipeline): int
    {
        if ($this->option('resume') !== null) {
            $run = ProductionRun::query()->find((int) $this->option('resume'));
            if ($run === null) {
                $this->components->error('Run tidak ditemukan.');

                return self::FAILURE;
            }
            if ($this->option('publish')) {
                $run->forceFill(['options' => ($run->options ?? []) + ['auto_publish' => true]])->save();
            }
        } else {
            $workspace = $this->option('workspace')
                ? Workspace::query()->find((int) $this->option('workspace'))
                : Workspace::query()->orderBy('id')->first();

            if ($workspace === null) {
                $this->components->error('Workspace tidak ditemukan. Jalankan studio:user-create terlebih dahulu.');

                return self::FAILURE;
            }

            if ($workspace->productionRuns()->whereIn('status', ['queued', 'running'])->exists()) {
                $this->components->warn('Masih ada produksi otomatis yang berjalan; dilewati.');

                return self::SUCCESS;
            }

            $owner = $workspace->members()->wherePivot('role', 'owner')->first() ?? User::query()->orderBy('id')->first();
            $channel = $workspace->channels()->where('is_active', true)->where('platform', 'youtube')->orderBy('id')->first();

            $run = $workspace->productionRuns()->create([
                'requested_by' => $owner->id,
                'channel_id' => $channel?->id,
                'status' => 'queued',
                'options' => array_filter([
                    'theme' => $this->option('theme'),
                    'format' => $this->option('format'),
                    'auto_publish' => $this->option('publish') ? true : (bool) config('services.production.auto_publish', false),
                ], fn ($v) => $v !== null),
            ]);
        }

        $this->components->info("Produksi otomatis #{$run->id} dimulai.");

        if ($this->option('sync')) {
            $run->forceFill(['status' => 'queued'])->save();
            $pipeline->run($run);
            $run->refresh();

            $this->components->twoColumnDetail('Status', $run->status);
            foreach ($run->stages ?? [] as $key => $state) {
                $this->components->twoColumnDetail(ProductionRun::STAGES[$key] ?? $key, ($state['status'] ?? '?').(! empty($state['message']) ? ' — '.$state['message'] : ''));
            }
            if ($run->error_message) {
                $this->components->error($run->error_message);
            }

            return in_array($run->status, ['completed', 'awaiting_approval'], true) ? self::SUCCESS : self::FAILURE;
        }

        RunProduction::dispatch($run->id);
        $this->line('Dikirim ke antrean. Pastikan worker berjalan: php artisan queue:work --timeout=0');

        return self::SUCCESS;
    }
}
