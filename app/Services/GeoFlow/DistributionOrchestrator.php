<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleDuplicateGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DistributionOrchestrator
{
    public function __construct(
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionPublisherManager $publisherManager,
        private readonly TaskDistributionChannelSelector $channelSelector,
        private readonly ArticleRiskGate $articleRiskGate,
        private readonly ArticleDuplicateGate $articleDuplicateGate,
    ) {}

    /**
     * @param  list<int>  $channelIds
     */
    public function syncTaskChannels(Task $task, array $channelIds): void
    {
        $activeIds = DistributionChannel::query()
            ->whereIn('id', $channelIds)
            ->where('status', 'active')
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);

        $syncPayload = [];
        $sortOrder = 0;
        $seen = [];
        foreach (array_values($channelIds) as $channelId) {
            $id = (int) $channelId;
            if ($id <= 0 || isset($seen[$id]) || ! isset($activeIds[$id])) {
                continue;
            }
            $seen[$id] = true;

            $syncPayload[$id] = [
                'sort_order' => $sortOrder++,
                'trigger' => 'after_local_publish',
                'remote_status' => 'follow_local',
                'failure_policy' => 'ignore_distribution_failure',
                'max_attempts' => 3,
            ];
        }

        $task->distributionChannels()->sync($syncPayload);
    }

    public function enqueueForArticle(
        int|Article $article,
        string $action = 'publish',
        bool $forceAllChannels = false,
        bool $skipSynced = false,
    ): int {
        try {
            $articleModel = $article instanceof Article
                ? $article
                : Article::query()->whereKey($article)->first();

            if (! $articleModel || ! $articleModel->task_id) {
                return 0;
            }

            $articleModel->load('task.distributionChannels');
            $publishScope = (string) ($articleModel->task?->publish_scope ?? 'local_and_distribution');
            if ($publishScope === 'local_only') {
                return 0;
            }
            $canDistribute = $articleModel->status === 'published'
                || ($publishScope === 'distribution_only' && in_array((string) $articleModel->status, ['private', 'published'], true));
            if (! $canDistribute) {
                return 0;
            }

            $channels = $articleModel->task?->distributionChannels
                ?->where('status', 'active') ?? new Collection;

            if ($channels->isEmpty()) {
                return 0;
            }

            $channels = $forceAllChannels
                ? collect($channels->all())
                    ->sortBy(static fn (DistributionChannel $channel): string => sprintf(
                        '%010d-%010d',
                        (int) ($channel->pivot?->sort_order ?? 0),
                        (int) $channel->id,
                    ))
                    ->values()
                : $this->channelSelector->selectChannelsForArticle($articleModel, $channels, $action);

            if ($channels->isEmpty()) {
                return 0;
            }

            return $this->enqueueChannels($articleModel, $channels, $action, $skipSynced);
        } catch (Throwable $e) {
            $this->log('error', '文章分发入队失败：'.$e->getMessage(), null, null, $article instanceof Article ? (int) $article->id : $article, [
                'event' => 'distribution.enqueue_failed',
            ]);

            return 0;
        }
    }

    /**
     * Enqueue an explicit ordered channel list without requiring a task relation.
     *
     * @param  list<int>  $channelIds
     */
    public function enqueueForArticleChannels(
        int|Article $article,
        array $channelIds,
    ): int {
        try {
            $articleModel = $article instanceof Article
                ? $article
                : Article::query()->whereKey($article)->first();

            if (! $articleModel || ! in_array((string) $articleModel->status, ['published', 'private'], true)) {
                throw new ApiException(
                    'distribution_enqueue_failed',
                    '文章状态不允许进入指定渠道分发队列',
                    409,
                );
            }

            $orderedIds = collect($channelIds)
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            if ($orderedIds->isEmpty()) {
                return 0;
            }

            $activeChannels = DistributionChannel::query()
                ->whereIn('id', $orderedIds->all())
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (DistributionChannel $channel): int => (int) $channel->id);

            if ($activeChannels->count() !== $orderedIds->count()) {
                throw new ApiException(
                    'distribution_channels_changed',
                    '指定分发渠道的启用状态已变化，文章发布已回滚',
                    409,
                    ['distribution_channel_ids' => $orderedIds->all()],
                );
            }

            $channels = $orderedIds
                ->map(static fn (int $id): DistributionChannel => $activeChannels->get($id));
            $payload = $this->buildVerifiedPayload($articleModel, 'distribution_enqueue');
            $payloadHash = $this->explicitPayloadHash($payload);

            return $this->enqueueExplicitChannels($articleModel, $channels, $payloadHash);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', '文章分发入队失败：'.$e->getMessage(), null, null, $article instanceof Article ? (int) $article->id : $article, [
                'event' => 'distribution.enqueue_failed',
            ]);

            throw new ApiException(
                'distribution_enqueue_failed',
                '指定渠道未能全部进入分发队列，文章发布已回滚',
                503,
                ['distribution_channel_ids' => array_values($channelIds)],
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function healthCheck(DistributionChannel $channel): array
    {
        return $this->publisherManager->forChannel($channel)->health($channel);
    }

    public function process(ArticleDistribution $distribution): void
    {
        $this->processDistribution($distribution, false);
    }

    public function processClaimed(ArticleDistribution $distribution): void
    {
        $this->processDistribution($distribution, true);
    }

    private function processDistribution(ArticleDistribution $distribution, bool $alreadyClaimed): void
    {
        $distribution->loadMissing(['article', 'channel']);
        $article = $distribution->article;
        $channel = $distribution->channel;
        if (! $article || ! $channel) {
            throw new \RuntimeException('分发记录缺少文章或渠道');
        }

        $payload = (string) $distribution->action === 'delete'
            ? []
            : $this->buildVerifiedPayload($article, 'distribution_send');
        if ((string) $distribution->action === 'update') {
            $payload['event'] = 'article.update';
        }

        if ($alreadyClaimed && (string) $distribution->status !== 'sending') {
            return;
        }
        if (! $alreadyClaimed) {
            $distribution->forceFill([
                'status' => 'sending',
                'attempt_count' => (int) $distribution->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_message' => null,
            ])->save();
        }

        $publisher = $this->publisherManager->forChannel($channel);
        $response = match ((string) $distribution->action) {
            'update' => $publisher->update($distribution, $payload),
            'delete' => $publisher->delete($distribution),
            default => $publisher->publish($distribution, $payload),
        };
        $existingMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
        $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
        $completedStatus = (string) ($response['status'] ?? '') === 'simulated' ? 'simulated' : 'synced';
        $distribution->forceFill([
            'status' => $completedStatus,
            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
            'remote_url' => (string) $distribution->action === 'delete'
                ? null
                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
            'remote_meta' => array_replace($existingMeta, $responseMeta),
            'last_error_message' => null,
        ])->save();

        $this->log('info', $completedStatus === 'simulated' ? '文章模拟发布完成' : '文章分发成功', $channel->id, $distribution->id, $article->id, $response);
    }

    public function updateRemoteArticle(ArticleDistribution $distribution): void
    {
        $this->sendImmediateAction($distribution, 'update');
    }

    public function deleteRemoteArticle(ArticleDistribution $distribution): void
    {
        $this->sendImmediateAction($distribution, 'delete');
    }

    public function enqueueChannelContentRefresh(DistributionChannel $channel): int
    {
        $count = 0;

        ArticleDistribution::query()
            ->with('article:id,status')
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('action', '!=', 'delete')
            ->whereHas('article', function ($query): void {
                $query->whereIn('status', ['published', 'private']);
            })
            ->orderBy('id')
            ->chunkById(100, function ($distributions) use (&$count, $channel): void {
                foreach ($distributions as $distribution) {
                    if (! $distribution instanceof ArticleDistribution || ! $distribution->article) {
                        continue;
                    }

                    $distribution->forceFill([
                        'action' => 'update',
                        'status' => 'queued',
                        'last_error_message' => null,
                        'next_retry_at' => now(),
                        'idempotency_key' => $this->idempotencyKey((int) $distribution->article_id, (int) $channel->id, 'update'),
                    ])->save();

                    ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                        ->onQueue('distribution')
                        ->afterCommit();

                    $count++;
                }
            });

        if ($count > 0) {
            $this->log(
                'info',
                '目标站点内容刷新已入队',
                (int) $channel->id,
                null,
                null,
                ['event' => 'target.content_refresh_queued', 'count' => $count]
            );
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function log(string $level, string $message, ?int $channelId = null, ?int $distributionId = null, ?int $articleId = null, array $context = []): void
    {
        DistributionLog::query()->create([
            'distribution_channel_id' => $channelId,
            'article_distribution_id' => $distributionId,
            'article_id' => $articleId,
            'level' => $level,
            'event' => is_string($context['event'] ?? null) ? (string) $context['event'] : null,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }

    private function idempotencyKey(int $articleId, int $channelId, string $action): string
    {
        return 'article-'.$articleId.'-channel-'.$channelId.'-'.$action.'-v1';
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     */
    private function enqueueChannels(
        Article $article,
        Collection $channels,
        string $action,
        bool $skipSynced,
    ): int {
        $payload = $action === 'delete'
            ? $this->payloadBuilder->build($article)
            : $this->buildVerifiedPayload($article, 'distribution_enqueue');
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $queuedCount = 0;
        foreach ($channels as $channel) {
            $existingDistribution = ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->where('distribution_channel_id', (int) $channel->id)
                ->where('action', $action)
                ->first();

            if ($skipSynced && in_array((string) $existingDistribution?->status, ['queued', 'sending', 'synced'], true)) {
                $this->log('info', '渠道已有待处理或成功发布记录，本次一键分发已跳过', $channel->id, $existingDistribution?->id, $article->id, [
                    'event' => 'distribution.skipped_already_handled',
                    'existing_status' => (string) $existingDistribution?->status,
                ]);

                continue;
            }

            $distribution = ArticleDistribution::query()->updateOrCreate(
                [
                    'article_id' => (int) $article->id,
                    'distribution_channel_id' => (int) $channel->id,
                    'action' => $action,
                ],
                [
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $this->idempotencyKey((int) $article->id, (int) $channel->id, $action),
                ]
            );

            $this->log('info', '文章已进入分发队列', $channel->id, $distribution->id, $article->id, [
                'event' => 'distribution.queued',
                'strategy' => (string) ($article->task?->distribution_strategy ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
            ]);
            ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                ->onQueue('distribution')
                ->afterCommit();
            $queuedCount++;
        }

        return $queuedCount;
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     */
    private function enqueueExplicitChannels(
        Article $article,
        Collection $channels,
        string $payloadHash,
    ): int {
        $handledCount = 0;
        foreach ($channels as $channel) {
            $existingDistribution = ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->where('distribution_channel_id', (int) $channel->id)
                ->whereIn('action', ['publish', 'update'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existingDistribution !== null) {
                $existingStatus = (string) $existingDistribution->status;
                $samePayload = hash_equals((string) ($existingDistribution->payload_hash ?? ''), $payloadHash);

                if ($samePayload && in_array($existingStatus, ['queued', 'sending', 'synced'], true)) {
                    $this->log('info', '渠道已有相同内容的待处理或成功发布记录，本次显式分发已跳过', $channel->id, $existingDistribution->id, $article->id, [
                        'event' => 'distribution.skipped_same_payload',
                        'existing_status' => $existingStatus,
                    ]);
                    $handledCount++;

                    continue;
                }

                if ($existingStatus === 'sending') {
                    throw new ApiException(
                        'distribution_payload_change_in_progress',
                        '渠道正在发送旧版本内容，请等待完成后重试',
                        409,
                        ['distribution_channel_id' => (int) $channel->id],
                    );
                }

                if ($existingStatus === 'queued') {
                    $existingDistribution->forceFill([
                        'payload_hash' => $payloadHash,
                        'next_retry_at' => now(),
                        'last_error_message' => null,
                    ])->save();
                    $handledCount++;

                    continue;
                }

                $action = $existingStatus === 'synced' || $existingDistribution->remote_id !== null
                    ? 'update'
                    : 'publish';
                $existingDistribution->forceFill([
                    'action' => $action,
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'last_error_message' => null,
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $this->idempotencyKey((int) $article->id, (int) $channel->id, $action),
                ])->save();
                $distribution = $existingDistribution;
            } else {
                $distribution = ArticleDistribution::query()->create([
                    'article_id' => (int) $article->id,
                    'distribution_channel_id' => (int) $channel->id,
                    'action' => 'publish',
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $this->idempotencyKey((int) $article->id, (int) $channel->id, 'publish'),
                ]);
            }

            $this->log('info', '文章已进入显式分发队列', $channel->id, $distribution->id, $article->id, [
                'event' => 'distribution.explicit_queued',
                'action' => (string) $distribution->action,
            ]);
            ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                ->onQueue('distribution')
                ->afterCommit();
            $handledCount++;
        }

        if ($handledCount !== $channels->count()) {
            throw new ApiException(
                'distribution_enqueue_failed',
                '指定渠道未能全部进入分发队列，文章发布已回滚',
                503,
            );
        }

        return $handledCount;
    }

    /**
     * Ignore the local row timestamp so an unchanged explicit republish remains idempotent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function explicitPayloadHash(array $payload): string
    {
        if (is_array($payload['article'] ?? null)) {
            unset($payload['article']['updated_at']);
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function sendImmediateAction(ArticleDistribution $distribution, string $action): void
    {
        $distribution->loadMissing(['article', 'channel']);
        $article = $distribution->article;
        $channel = $distribution->channel;
        if (! $article || ! $channel) {
            throw new \RuntimeException('分发记录缺少文章或渠道');
        }

        $payload = $action === 'delete' ? [] : $this->buildVerifiedPayload($article, 'distribution_send');
        if ($action === 'update') {
            $payload['event'] = 'article.update';
        }
        $payloadHash = $action === 'delete'
            ? null
            : hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $distribution->forceFill([
            'action' => $action,
            'status' => 'sending',
            'attempt_count' => (int) $distribution->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error_message' => null,
            'payload_hash' => $payloadHash,
            'idempotency_key' => $this->idempotencyKey((int) $article->id, (int) $channel->id, $action),
        ])->save();

        $publisher = $this->publisherManager->forChannel($channel);
        $response = $action === 'delete'
            ? $publisher->delete($distribution)
            : $publisher->update($distribution, $payload);

        $existingMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
        $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
        $distribution->forceFill([
            'status' => 'synced',
            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
            'remote_url' => $action === 'delete'
                ? null
                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
            'remote_meta' => array_replace($existingMeta, $responseMeta),
            'last_error_message' => null,
        ])->save();

        $this->log(
            'info',
            $action === 'delete' ? '远端文章副本已删除' : '远端文章已更新',
            (int) $channel->id,
            (int) $distribution->id,
            (int) $article->id,
            ['event' => 'article.'.$action, 'remote_result' => $response]
        );
    }

    /**
     * Build an immutable payload from the row-locked article snapshot that passed the risk gate.
     *
     * @return array<string, mixed>
     */
    private function buildVerifiedPayload(Article $article, string $trigger): array
    {
        $result = DB::transaction(function () use ($article, $trigger): Article|ArticleRiskGateException|ArticleDuplicateGateException {
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedArticle->load([
                'category:id,name,slug',
                'author:id,name',
                'task:id,name,publish_scope',
                'articleImages.image',
            ]);
            if (! $this->isDistributableSnapshot($lockedArticle)) {
                throw new \RuntimeException('文章当前状态不允许分发');
            }

            try {
                $this->articleRiskGate->check($lockedArticle, $trigger);
                $this->articleDuplicateGate->check($lockedArticle, $trigger);
            } catch (ArticleRiskGateException|ArticleDuplicateGateException $exception) {
                return $exception;
            }

            return clone $lockedArticle;
        });

        if ($result instanceof ArticleRiskGateException || $result instanceof ArticleDuplicateGateException) {
            throw $result;
        }

        return $this->payloadBuilder->build($result);
    }

    private function isDistributableSnapshot(Article $article): bool
    {
        if ($article->task === null) {
            return in_array((string) $article->status, ['published', 'private'], true);
        }

        if (! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
            return false;
        }

        $publishScope = (string) ($article->task->publish_scope ?? 'local_and_distribution');
        if ($publishScope === 'local_only') {
            return false;
        }

        return $article->status === 'published'
            || ($publishScope === 'distribution_only' && $article->status === 'private');
    }
}
