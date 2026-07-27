<?php

namespace App\Services\AiExposure;

use App\Events\Admin\AiExposureOverviewUpdated;
use App\Services\Admin\AiExposureDashboardService;
use Throwable;

class AiExposureRealtimeBroadcastService
{
    public function __construct(
        private readonly AiExposureDashboardService $dashboardService,
    ) {}

    public function broadcastOverview(): void
    {
        try {
            broadcast(new AiExposureOverviewUpdated($this->dashboardService->buildRealtimeOverview()));
        } catch (Throwable) {
            // Realtime delivery must not change the persisted monitoring result.
        }
    }
}
