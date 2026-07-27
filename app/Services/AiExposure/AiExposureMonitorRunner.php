<?php

namespace App\Services\AiExposure;

use App\Contracts\AiExposure\AiExposureAnswerProvider;
use App\Models\AiExposureResult;
use App\Models\AiExposureRun;
use App\Models\AiModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiExposureMonitorRunner
{
    private const MAX_ANSWER_CHARACTERS = 200000;

    public function __construct(
        private readonly AiExposureAnswerProvider $answerProvider,
        private readonly AiExposureSourceResolver $sourceResolver,
        private readonly AiExposureAnswerAnalyzer $answerAnalyzer,
        private readonly AiExposureRealtimeBroadcastService $realtimeBroadcastService,
    ) {}

    public function runResult(int $resultId): void
    {
        $result = AiExposureResult::query()
            ->with(['aiModel', 'run.monitor.article'])
            ->find($resultId);

        if (! $result || $result->status === AiExposureResult::STATUS_SUCCEEDED) {
            return;
        }

        $run = $result->run;
        $monitor = $run?->monitor;
        $article = $monitor?->article;
        if (! $run || ! $monitor || ! $article) {
            $this->failResult($resultId, 'The monitored article no longer exists.');

            return;
        }

        AiExposureRun::query()
            ->whereKey($run->id)
            ->where('status', AiExposureRun::STATUS_QUEUED)
            ->update([
                'status' => AiExposureRun::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        $result->forceFill([
            'status' => AiExposureResult::STATUS_RUNNING,
            'mentioned' => false,
            'cited' => false,
            'error_message' => null,
        ])->save();

        $model = $this->requireAvailableModel($result->aiModel);
        $answer = $this->answerProvider->answer($model, (string) $result->platform, (string) $monitor->query);
        $answer = mb_substr($answer, 0, self::MAX_ANSWER_CHARACTERS);
        $analysis = $this->answerAnalyzer->analyze(
            $answer,
            (string) $article->title,
            $this->sourceResolver->forArticle($article)
        );
        $modelSnapshot = (array) $result->response_meta;

        $result->forceFill([
            'status' => AiExposureResult::STATUS_SUCCEEDED,
            'mentioned' => $analysis['mentioned'],
            'cited' => $analysis['cited'],
            'answer_text' => $answer,
            'cited_urls' => $analysis['cited_urls'],
            'matched_sources' => $analysis['matched_sources'],
            'response_meta' => $modelSnapshot + [
                'executed_model_name' => (string) $model->name,
                'executed_model_id' => (string) $model->model_id,
            ],
            'error_message' => null,
            'checked_at' => now(),
        ])->save();

        AiModel::query()->whereKey($model->id)->incrementEach([
            'used_today' => 1,
            'total_used' => 1,
        ]);

        $this->finalizeRun((int) $run->id);
        $this->realtimeBroadcastService->broadcastOverview();
    }

    public function failResult(int $resultId, string $message): void
    {
        $result = AiExposureResult::query()->find($resultId);
        if (! $result || in_array($result->status, [AiExposureResult::STATUS_SUCCEEDED, AiExposureResult::STATUS_FAILED], true)) {
            return;
        }

        $result->forceFill([
            'status' => AiExposureResult::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'checked_at' => now(),
        ])->save();

        $this->finalizeRun((int) $result->ai_exposure_run_id);
        $this->realtimeBroadcastService->broadcastOverview();
    }

    private function requireAvailableModel(?AiModel $model): AiModel
    {
        if (! $model) {
            throw new RuntimeException('This platform has no AI model configured.');
        }
        if ($model->status !== 'active') {
            throw new RuntimeException('The configured AI model is inactive.');
        }

        $dailyLimit = (int) $model->daily_limit;
        if ($dailyLimit > 0 && (int) $model->used_today >= $dailyLimit) {
            throw new RuntimeException('The configured AI model has reached its daily limit.');
        }

        return $model;
    }

    private function finalizeRun(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = AiExposureRun::query()->with('monitor')->lockForUpdate()->find($runId);
            if (! $run || in_array($run->status, [AiExposureRun::STATUS_COMPLETED, AiExposureRun::STATUS_PARTIAL, AiExposureRun::STATUS_FAILED], true)) {
                return;
            }

            $terminalCount = AiExposureResult::query()
                ->where('ai_exposure_run_id', $run->id)
                ->whereIn('status', [AiExposureResult::STATUS_SUCCEEDED, AiExposureResult::STATUS_FAILED])
                ->count();
            if ($terminalCount < (int) $run->platform_total) {
                return;
            }

            $summary = AiExposureResult::query()
                ->where('ai_exposure_run_id', $run->id)
                ->selectRaw("SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded")
                ->selectRaw("SUM(CASE WHEN status = 'succeeded' AND mentioned THEN 1 ELSE 0 END) AS mentioned")
                ->selectRaw("SUM(CASE WHEN status = 'succeeded' AND cited THEN 1 ELSE 0 END) AS cited")
                ->first();

            $succeeded = (int) ($summary?->succeeded ?? 0);
            $status = $succeeded === (int) $run->platform_total
                ? AiExposureRun::STATUS_COMPLETED
                : ($succeeded > 0 ? AiExposureRun::STATUS_PARTIAL : AiExposureRun::STATUS_FAILED);

            $run->forceFill([
                'status' => $status,
                'platform_succeeded' => $succeeded,
                'mentioned_count' => (int) ($summary?->mentioned ?? 0),
                'cited_count' => (int) ($summary?->cited ?? 0),
                'completed_at' => now(),
            ])->save();

            $run->monitor?->forceFill(['last_completed_at' => now()])->save();
        });
    }
}
