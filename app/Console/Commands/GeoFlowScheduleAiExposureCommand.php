<?php

namespace App\Console\Commands;

use App\Models\AiExposureMonitor;
use App\Services\AiExposure\AiExposureRunDispatcher;
use DomainException;
use Illuminate\Console\Command;

class GeoFlowScheduleAiExposureCommand extends Command
{
    protected $signature = 'geoflow:schedule-ai-exposure {--limit=100}';

    protected $description = 'Enqueue due AI answer exposure monitors';

    public function handle(AiExposureRunDispatcher $dispatcher): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $queued = 0;
        $skipped = 0;

        $monitors = AiExposureMonitor::query()
            ->where('status', AiExposureMonitor::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($monitors as $monitor) {
            try {
                $dispatcher->dispatch($monitor, $monitor->next_run_at);
                $queued++;
            } catch (DomainException) {
                $skipped++;
            }
        }

        $this->info(sprintf('AI exposure scheduler done: queued=%d, skipped=%d', $queued, $skipped));

        return self::SUCCESS;
    }
}
