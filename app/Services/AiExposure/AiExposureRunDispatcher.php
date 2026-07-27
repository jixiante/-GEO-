<?php

namespace App\Services\AiExposure;

use App\Jobs\RunAiExposurePlatformCheckJob;
use App\Models\AiExposureMonitor;
use App\Models\AiExposurePlatformConfig;
use App\Models\AiExposureResult;
use App\Models\AiExposureRun;
use App\Support\AiExposure\AiExposurePlatformCatalog;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiExposureRunDispatcher
{
    public function __construct(private readonly AiExposureSourceResolver $sourceResolver) {}

    public function dispatch(AiExposureMonitor $monitor, ?Carbon $scheduledFor = null): AiExposureRun
    {
        if ($monitor->status !== AiExposureMonitor::STATUS_ACTIVE) {
            throw new DomainException('The monitor is paused.');
        }

        $article = $monitor->article()->first();
        if (! $article || $this->sourceResolver->forArticle($article) === []) {
            throw new DomainException('The article has no published website URL to monitor.');
        }

        $enabledPlatforms = AiExposurePlatformConfig::query()
            ->with('aiModel:id,name,model_id,api_url')
            ->where('enabled', true)
            ->whereIn('platform', AiExposurePlatformCatalog::keys())
            ->get(['platform', 'ai_model_id'])
            ->keyBy('platform');
        if ($enabledPlatforms->isEmpty()) {
            throw new DomainException('Enable and configure at least one AI platform first.');
        }

        [$run, $resultIds, $created] = DB::transaction(function () use ($monitor, $scheduledFor, $enabledPlatforms): array {
            $lockedMonitor = AiExposureMonitor::query()->lockForUpdate()->findOrFail($monitor->id);
            if ($lockedMonitor->status !== AiExposureMonitor::STATUS_ACTIVE) {
                throw new DomainException('The monitor is paused.');
            }

            $scheduledDispatchKey = $scheduledFor
                ? 'scheduled:'.$lockedMonitor->id.':'.$scheduledFor->format('YmdHis')
                : null;
            if ($scheduledFor && ($lockedMonitor->next_run_at === null || $lockedMonitor->next_run_at->greaterThan($scheduledFor))) {
                $alreadyDispatched = AiExposureRun::query()->where('dispatch_key', $scheduledDispatchKey)->first();
                if ($alreadyDispatched) {
                    return [$alreadyDispatched, [], false];
                }

                throw new DomainException('This scheduled slot has already advanced.');
            }

            $existing = AiExposureRun::query()
                ->where('ai_exposure_monitor_id', $lockedMonitor->id)
                ->whereIn('status', [AiExposureRun::STATUS_QUEUED, AiExposureRun::STATUS_RUNNING])
                ->latest('id')
                ->first();
            if ($existing) {
                return [$existing, [], false];
            }

            $dispatchKey = $scheduledFor
                ? $scheduledDispatchKey
                : 'manual:'.$lockedMonitor->id.':'.Str::uuid();
            $run = AiExposureRun::query()->create([
                'ai_exposure_monitor_id' => $lockedMonitor->id,
                'dispatch_key' => $dispatchKey,
                'status' => AiExposureRun::STATUS_QUEUED,
                'platform_total' => $enabledPlatforms->count(),
                'scheduled_for' => $scheduledFor ?? now(),
            ]);
            $resultIds = [];
            foreach (AiExposurePlatformCatalog::keys() as $platform) {
                $config = $enabledPlatforms->get($platform);
                if (! $config) {
                    continue;
                }

                $result = $run->results()->create([
                    'platform' => $platform,
                    'ai_model_id' => $config->ai_model_id,
                    'status' => AiExposureResult::STATUS_PENDING,
                    'response_meta' => [
                        'model_name' => (string) ($config->aiModel?->name ?? ''),
                        'model_id' => (string) ($config->aiModel?->model_id ?? ''),
                        'provider_url_host' => strtolower((string) (parse_url((string) ($config->aiModel?->api_url ?? ''), PHP_URL_HOST) ?? '')),
                    ],
                ]);
                $resultIds[] = ['id' => (int) $result->id, 'platform' => $platform];
            }

            $lockedMonitor->forceFill([
                'last_queued_at' => now(),
                'next_run_at' => $scheduledFor ? $lockedMonitor->nextScheduledAt($scheduledFor) : $lockedMonitor->next_run_at,
            ])->save();

            return [$run, $resultIds, true];
        });

        if ($created) {
            foreach ($resultIds as $result) {
                RunAiExposurePlatformCheckJob::dispatch($result['id'], $result['platform'])->onQueue('geoflow');
            }
        }

        return $run;
    }
}
