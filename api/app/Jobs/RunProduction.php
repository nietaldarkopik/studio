<?php

namespace App\Jobs;

use App\Models\ProductionRun;
use App\Services\Production\ProductionPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunProduction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Render Blender bisa berjam-jam. */
    public int $timeout = 21600;

    public function __construct(public int $runId)
    {
    }

    public function handle(ProductionPipeline $pipeline): void
    {
        $run = ProductionRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $pipeline->run($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ProductionRun::query()->find($this->runId);
        if ($run === null || ! $run->isActive()) {
            return;
        }

        $run->forceFill([
            'status' => 'failed',
            'error_message' => 'Proses produksi berhenti tak terduga (timeout atau worker mati). Lanjutkan untuk mengulang dari tahap terakhir.',
            'finished_at' => now(),
        ])->save();
    }
}
