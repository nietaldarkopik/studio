<?php

namespace App\Services\Workflow;

use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowEngine
{
    /**
     * Recompute the ready/blocked state of every pending step based on its
     * dependencies. Terminal and running steps keep their explicit state.
     */
    public function refresh(Workflow $workflow): void
    {
        $statuses = $workflow->steps()->pluck('status', 'key');
        $steps = $workflow->steps()->get();

        foreach ($steps as $step) {
            if (in_array($step->status, ['completed', 'failed', 'skipped', 'running'], true)) {
                continue;
            }

            $ready = collect($step->dependencies())
                ->every(fn (string $key) => $statuses[$key] === 'completed');

            $next = $ready ? 'ready' : 'blocked';
            if ($step->status !== $next) {
                $step->update(['status' => $next]);
            }
        }
    }

    public function transition(WorkflowStep $step, string $action): void
    {
        match ($action) {
            'start' => $this->start($step),
            'approve' => $this->approve($step),
            'complete' => $this->complete($step),
            'fail' => $this->fail($step),
            'skip' => $this->skip($step),
            'reset' => $this->reset($step),
            default => abort(422, 'Aksi workflow tidak dikenal.'),
        };
    }

    private function start(WorkflowStep $step): void
    {
        abort_unless($step->status === 'ready', 409, 'Langkah belum siap dijalankan.');
        $step->update(['status' => 'running', 'started_at' => now()]);
    }

    private function approve(WorkflowStep $step): void
    {
        abort_unless($step->requires_approval, 422, 'Langkah ini tidak memerlukan persetujuan.');
        abort_if($step->isApproved(), 409, 'Langkah ini sudah disetujui.');
        $step->update(['approved_at' => now()]);
    }

    private function complete(WorkflowStep $step): void
    {
        abort_unless($step->status === 'running', 409, 'Hanya langkah yang berjalan dapat diselesaikan.');
        abort_if(
            $step->requires_approval && ! $step->isApproved(),
            409,
            'Langkah ini memerlukan persetujuan sebelum diselesaikan.',
        );

        $step->update(['status' => 'completed', 'finished_at' => now()]);
        $this->refresh($step->workflow);
    }

    private function fail(WorkflowStep $step): void
    {
        abort_unless($step->status === 'running', 409, 'Hanya langkah yang berjalan dapat digagalkan.');
        $step->update(['status' => 'failed', 'finished_at' => now()]);
    }

    private function skip(WorkflowStep $step): void
    {
        abort_if(
            in_array($step->status, ['running', 'completed'], true),
            409,
            'Langkah ini tidak dapat dilewati.',
        );

        $step->update(['status' => 'skipped', 'finished_at' => now()]);
        $this->refresh($step->workflow);
    }

    private function reset(WorkflowStep $step): void
    {
        $step->update([
            'status' => 'pending',
            'approved_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        $this->refresh($step->workflow);
    }
}
