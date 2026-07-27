<?php

namespace App\Jobs;

use App\Services\AiExposure\AiExposureMonitorRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class RunAiExposurePlatformCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 270;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [30];

    public function __construct(
        public readonly int $resultId,
        public readonly string $platform,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->resultId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new RateLimited('ai-exposure'))->releaseAfter(30)];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-exposure', 'ai-platform:'.$this->platform, 'ai-exposure-result:'.$this->resultId];
    }

    public function handle(AiExposureMonitorRunner $runner): void
    {
        $runner->runResult($this->resultId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AiExposureMonitorRunner::class)->failResult(
            $this->resultId,
            (string) ($exception?->getMessage() ?? 'Queue job failed.')
        );
    }
}
