<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GeoFlowPublishAllCommand extends Command
{
    protected $signature = 'geoflow:publish-all
                            {articleId? : 要发布的文章 ID，留空时选择最新的可分发文章}
                            {--preflight : 只检查文章和渠道配置，不创建分发任务}';

    protected $description = '将一篇文章按配置顺序发布到任务关联的全部可用平台';

    /** @var array<string, string> */
    private const BROWSER_PLATFORM_LABELS = [
        'toutiao' => '今日头条',
        'baijiahao' => '百家号',
        'zhihu' => '知乎',
        'sohu' => '搜狐号',
        'netease' => '网易号',
        'csdn' => 'CSDN',
        'xiaohongshu' => '小红书',
    ];

    /** @var array<string, string> */
    private const CHANNEL_TYPE_LABELS = [
        DistributionChannel::CHANNEL_TYPE_GEOFLOW_AGENT => '点签站点',
        DistributionChannel::CHANNEL_TYPE_WORDPRESS_REST => 'WordPress',
        DistributionChannel::CHANNEL_TYPE_GENERIC_HTTP_API => '通用接口',
        DistributionChannel::CHANNEL_TYPE_TOUTIAO_BRIDGE => '今日头条接口',
        DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER => '浏览器平台',
    ];

    /** @var list<string> */
    private const ALREADY_HANDLED_STATUSES = ['queued', 'sending', 'synced'];

    public function handle(DistributionOrchestrator $orchestrator): int
    {
        $article = $this->resolveArticle();
        if (! $article instanceof Article) {
            return self::FAILURE;
        }

        $validationError = $this->validateArticle($article);
        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        $channels = $this->activeChannels($article);
        if ($channels->isEmpty()) {
            $this->error(sprintf(
                '文章 #%d 的任务“%s”尚未关联启用中的分发渠道。请先在“分发管理”中创建并启用渠道，再到任务编辑页勾选这些渠道。',
                (int) $article->id,
                (string) $article->task?->name,
            ));

            return self::FAILURE;
        }

        $requiresBrowserRunner = $channels->contains(
            static fn (DistributionChannel $channel): bool => $channel->isBrowserRunner()
        );

        if ((bool) $this->option('preflight')) {
            $this->line('GEOFLOW_ARTICLE_ID='.(int) $article->id);
            $this->line('GEOFLOW_BROWSER_RUNNER_REQUIRED='.($requiresBrowserRunner ? '1' : '0'));

            return self::SUCCESS;
        }

        $existingByChannel = ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('action', 'publish')
            ->whereIn('distribution_channel_id', $channels->pluck('id'))
            ->get()
            ->keyBy('distribution_channel_id');

        $this->info(sprintf('准备分发文章 #%d：%s', (int) $article->id, (string) $article->title));
        $this->line(sprintf('关联任务：%s；共 %d 个启用渠道。', (string) $article->task?->name, $channels->count()));
        $this->newLine();
        $this->table(
            ['顺序', '渠道', '平台', '处理方式'],
            $channels->values()->map(function (DistributionChannel $channel, int $index) use ($existingByChannel): array {
                $existing = $existingByChannel->get((int) $channel->id);

                return [
                    $index + 1,
                    (string) $channel->name,
                    $this->platformLabel($channel),
                    $this->handlingLabel($existing instanceof ArticleDistribution ? (string) $existing->status : null),
                ];
            })->all(),
        );

        $queuedCount = $orchestrator->enqueueForArticle($article, 'publish', true, true);
        $alreadyHandledCount = $existingByChannel
            ->filter(static fn (ArticleDistribution $distribution): bool => in_array(
                (string) $distribution->status,
                self::ALREADY_HANDLED_STATUSES,
                true,
            ))
            ->count();

        if ($queuedCount > 0) {
            $this->newLine();
            $this->info(sprintf(
                '已按上表顺序加入 %d 个发布任务；跳过 %d 个已在处理或已发布的渠道。单个分发 Worker 会依次执行。',
                $queuedCount,
                $alreadyHandledCount,
            ));

            return self::SUCCESS;
        }

        if ($alreadyHandledCount === $channels->count()) {
            $this->newLine();
            $this->info('没有创建重复任务：全部渠道均已在处理或已经发布成功。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('文章未能进入分发队列。请检查文章的风险检测、重复内容检测和 storage/logs/laravel.log 中的错误信息。');

        return self::FAILURE;
    }

    private function resolveArticle(): ?Article
    {
        $rawId = trim((string) ($this->argument('articleId') ?? ''));
        if ($rawId !== '' && (ctype_digit($rawId) === false || (int) $rawId <= 0)) {
            $this->error('文章 ID 必须是大于 0 的整数。');

            return null;
        }

        if ($rawId !== '') {
            $article = Article::query()
                ->with('task.distributionChannels')
                ->whereKey((int) $rawId)
                ->first();

            if (! $article instanceof Article) {
                $this->error('未找到文章 #'.(int) $rawId.'，请在文章管理页确认 ID。');
            }

            return $article;
        }

        $article = Article::query()
            ->with('task.distributionChannels')
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->whereHas('task', static function (Builder $query): void {
                $query->where('status', 'active')
                    ->where(function (Builder $scopeQuery): void {
                        $scopeQuery->whereNull('publish_scope')
                            ->orWhere('publish_scope', '!=', 'local_only');
                    });
            })
            ->where(function (Builder $query): void {
                $query->where('status', 'published')
                    ->orWhere(function (Builder $privateQuery): void {
                        $privateQuery->where('status', 'private')
                            ->whereHas('task', static fn (Builder $taskQuery): Builder => $taskQuery->where('publish_scope', 'distribution_only'));
                    });
            })
            ->orderByDesc('id')
            ->first();

        if (! $article instanceof Article) {
            $this->error('没有找到可分发文章。请确认文章已经审核通过，并且关联了状态为“启用”、发布范围不是“仅本地”的任务。');
        }

        return $article;
    }

    private function validateArticle(Article $article): ?string
    {
        if ($article->task === null) {
            return '文章 #'.(int) $article->id.' 没有关联任务，无法确定要发布到哪些平台。';
        }

        if ((string) $article->task->status !== 'active') {
            return '文章 #'.(int) $article->id.' 关联的任务未启用，请先在任务管理中启用该任务。';
        }

        if (! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
            return '文章 #'.(int) $article->id.' 尚未审核通过，请先完成审核。';
        }

        $publishScope = (string) ($article->task->publish_scope ?? 'local_and_distribution');
        if ($publishScope === 'local_only') {
            return '文章 #'.(int) $article->id.' 的任务发布范围是“仅本地”，请改为“本地及分发”或“仅分发”。';
        }

        $canDistribute = (string) $article->status === 'published'
            || ($publishScope === 'distribution_only' && (string) $article->status === 'private');
        if (! $canDistribute) {
            return '文章 #'.(int) $article->id.' 当前状态不允许对外分发。';
        }

        return null;
    }

    /** @return Collection<int, DistributionChannel> */
    private function activeChannels(Article $article): Collection
    {
        return collect($article->task?->distributionChannels?->all() ?? [])
            ->filter(static fn (DistributionChannel $channel): bool => (string) $channel->status === 'active')
            ->sortBy(static fn (DistributionChannel $channel): string => sprintf(
                '%010d-%010d',
                (int) ($channel->pivot?->sort_order ?? 0),
                (int) $channel->id,
            ))
            ->values();
    }

    private function platformLabel(DistributionChannel $channel): string
    {
        if ($channel->isBrowserRunner()) {
            $platform = (string) $channel->resolvedBrowserRunnerConfig()['browser_platform'];

            return self::BROWSER_PLATFORM_LABELS[$platform] ?? $platform;
        }

        return self::CHANNEL_TYPE_LABELS[$channel->channelType()] ?? $channel->channelType();
    }

    private function handlingLabel(?string $status): string
    {
        return match ($status) {
            'queued' => '已在队列，跳过重复入队',
            'sending' => '正在发布，跳过重复入队',
            'synced' => '已发布成功，跳过',
            'failed' => '上次失败，重新入队',
            default => '等待入队',
        };
    }
}
