<?php

namespace App\Services\Admin;

use App\Models\AiExposureMonitor;
use App\Models\AiExposurePlatformConfig;
use App\Models\AiExposureResult;
use App\Models\AiExposureRun;
use App\Models\AiModel;
use App\Models\Article;
use App\Services\AiExposure\AiExposureSourceResolver;
use App\Support\AiExposure\AiExposurePlatformCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AiExposureDashboardService
{
    /** @var list<string> */
    private const TERMINAL_RUN_STATUSES = [
        AiExposureRun::STATUS_COMPLETED,
        AiExposureRun::STATUS_PARTIAL,
        AiExposureRun::STATUS_FAILED,
    ];

    public function __construct(private readonly AiExposureSourceResolver $sourceResolver) {}

    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        $filters = [
            'platform' => AiExposurePlatformCatalog::has((string) $request->query('platform'))
                ? (string) $request->query('platform')
                : '',
            'exposure' => in_array($request->query('exposure'), ['mentioned', 'cited', 'not_exposed', 'failed'], true)
                ? (string) $request->query('exposure')
                : '',
            'article_id' => max(0, (int) $request->query('article_id')),
        ];

        $since = now()->subDays(30);
        $realtimeOverview = $this->buildRealtimeOverview($since);
        $configs = AiExposurePlatformConfig::query()->with('aiModel')->get()->keyBy('platform');
        $platformStats = collect($realtimeOverview['platforms']);

        $platformRows = [];
        foreach (AiExposurePlatformCatalog::all() as $key => $platform) {
            $config = $configs->get($key);
            $stats = $platformStats->get($key);
            $platformRows[] = [
                'key' => $key,
                'name' => $platform['name'],
                'short_name' => $platform['short_name'],
                'enabled' => (bool) ($config?->enabled ?? false),
                'ai_model_id' => $config?->ai_model_id,
                'model_name' => (string) ($config?->aiModel?->name ?? ''),
                'sample_count' => (int) ($stats['sample_count'] ?? 0),
                'mentioned_count' => (int) ($stats['mentioned_count'] ?? 0),
                'cited_count' => (int) ($stats['cited_count'] ?? 0),
                'last_checked_at' => $stats['last_checked_at'] ?? null,
            ];
        }

        $recentResultsQuery = AiExposureResult::query()
            ->with([
                'aiModel:id,name,model_id',
                'run:id,ai_exposure_monitor_id,status,completed_at',
                'run.monitor:id,article_id,query',
                'run.monitor.article:id,title,slug',
            ])
            ->orderByDesc('checked_at')
            ->orderByDesc('id');
        $this->applyResultFilters($recentResultsQuery, $filters);

        $monitors = $this->monitorStatisticsQuery()
            ->with(['article:id,title,slug,status', 'latestRun'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
        [$monitorArticleIds, $allMonitorSources] = $this->monitorSources();
        $monitorSources = [];
        foreach ($monitors as $monitor) {
            $monitorSources[(int) $monitor->id] = $allMonitorSources[(int) $monitor->id] ?? [];
        }

        return [
            'filters' => $filters,
            'metrics' => $realtimeOverview['metrics'],
            'platforms' => $platformRows,
            'chatModels' => $this->chatModels(),
            'articleOptions' => $this->articleOptions(),
            'monitors' => $monitors,
            'monitorSources' => $monitorSources,
            'sourceRows' => $this->sourceRows($monitorArticleIds, $allMonitorSources, $since),
            'recentResults' => $recentResultsQuery->paginate(20)->withQueryString(),
        ];
    }

    /**
     * @return array{
     *     metrics:array{active_monitors:int,sample_count:int,mentioned_count:int,cited_count:int,citation_rate:float},
     *     platforms:array<string,array{sample_count:int,mentioned_count:int,cited_count:int,last_checked_at:mixed}>,
     *     monitors:array<int,array{run_count:int,mentioned_count:int,cited_count:int}>
     * }
     */
    public function buildRealtimeOverview(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);
        $platforms = AiExposureResult::query()
            ->where('status', AiExposureResult::STATUS_SUCCEEDED)
            ->where('checked_at', '>=', $since)
            ->select('platform')
            ->selectRaw('COUNT(*) AS sample_count')
            ->selectRaw('SUM(CASE WHEN mentioned THEN 1 ELSE 0 END) AS mentioned_count')
            ->selectRaw('SUM(CASE WHEN cited THEN 1 ELSE 0 END) AS cited_count')
            ->selectRaw('MAX(checked_at) AS last_checked_at')
            ->groupBy('platform')
            ->get()
            ->mapWithKeys(static fn (AiExposureResult $stats): array => [
                (string) $stats->platform => [
                    'sample_count' => (int) $stats->getAttribute('sample_count'),
                    'mentioned_count' => (int) $stats->getAttribute('mentioned_count'),
                    'cited_count' => (int) $stats->getAttribute('cited_count'),
                    'last_checked_at' => $stats->getAttribute('last_checked_at'),
                ],
            ])
            ->all();

        $metrics = AiExposureResult::query()
            ->where('status', AiExposureResult::STATUS_SUCCEEDED)
            ->where('checked_at', '>=', $since)
            ->selectRaw('COUNT(*) AS sample_count')
            ->selectRaw('SUM(CASE WHEN mentioned THEN 1 ELSE 0 END) AS mentioned_count')
            ->selectRaw('SUM(CASE WHEN cited THEN 1 ELSE 0 END) AS cited_count')
            ->first();
        $sampleCount = (int) ($metrics?->getAttribute('sample_count') ?? 0);
        $mentionedCount = (int) ($metrics?->getAttribute('mentioned_count') ?? 0);
        $citedCount = (int) ($metrics?->getAttribute('cited_count') ?? 0);

        $monitors = $this->monitorStatisticsQuery()
            ->get(['id'])
            ->mapWithKeys(static fn (AiExposureMonitor $monitor): array => [
                (int) $monitor->id => [
                    'run_count' => (int) $monitor->getAttribute('run_count'),
                    'mentioned_count' => (int) $monitor->getAttribute('mentioned_count'),
                    'cited_count' => (int) $monitor->getAttribute('cited_count'),
                ],
            ])
            ->all();

        return [
            'metrics' => [
                'active_monitors' => AiExposureMonitor::query()
                    ->where('status', AiExposureMonitor::STATUS_ACTIVE)
                    ->count(),
                'sample_count' => $sampleCount,
                'mentioned_count' => $mentionedCount,
                'cited_count' => $citedCount,
                'citation_rate' => $sampleCount > 0 ? round($citedCount * 100 / $sampleCount, 1) : 0.0,
            ],
            'platforms' => $platforms,
            'monitors' => $monitors,
        ];
    }

    private function monitorStatisticsQuery(): Builder
    {
        return AiExposureMonitor::query()
            ->withCount([
                'runs as run_count' => fn (Builder $query): Builder => $query->whereIn('status', self::TERMINAL_RUN_STATUSES),
            ])
            ->withSum([
                'runs as mentioned_count' => fn (Builder $query): Builder => $query->whereIn('status', self::TERMINAL_RUN_STATUSES),
            ], 'mentioned_count')
            ->withSum([
                'runs as cited_count' => fn (Builder $query): Builder => $query->whereIn('status', self::TERMINAL_RUN_STATUSES),
            ], 'cited_count');
    }

    /** @param array{platform:string,exposure:string,article_id:int} $filters */
    private function applyResultFilters(Builder $query, array $filters): void
    {
        if ($filters['platform'] !== '') {
            $query->where('platform', $filters['platform']);
        }

        match ($filters['exposure']) {
            'mentioned' => $query->where('status', AiExposureResult::STATUS_SUCCEEDED)->where('mentioned', true),
            'cited' => $query->where('status', AiExposureResult::STATUS_SUCCEEDED)->where('cited', true),
            'not_exposed' => $query->where('status', AiExposureResult::STATUS_SUCCEEDED)
                ->where('mentioned', false)
                ->where('cited', false),
            'failed' => $query->where('status', AiExposureResult::STATUS_FAILED),
            default => null,
        };

        if ($filters['article_id'] > 0) {
            $query->whereHas('run.monitor', function (Builder $monitorQuery) use ($filters): void {
                $monitorQuery->where('article_id', $filters['article_id']);
            });
        }
    }

    /** @return Collection<int, AiModel> */
    private function chatModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('name')
            ->get(['id', 'name', 'model_id', 'api_url']);
    }

    /** @return Collection<int, Article> */
    private function articleOptions(): Collection
    {
        return Article::query()
            ->whereNull('deleted_at')
            ->where(function (Builder $query): void {
                $query->where('status', 'published')
                    ->orWhereHas('distributions', function (Builder $distributionQuery): void {
                        $distributionQuery->where('status', 'synced')
                            ->where('action', '!=', 'delete')
                            ->whereNotNull('remote_url');
                    });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'title', 'slug', 'status', 'original_keyword']);
    }

    /**
     * @return array{0:array<int, int>,1:array<int, list<array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>>}
     */
    private function monitorSources(): array
    {
        $monitorArticleIds = AiExposureMonitor::query()
            ->pluck('article_id', 'id')
            ->mapWithKeys(static fn (mixed $articleId, mixed $monitorId): array => [(int) $monitorId => (int) $articleId])
            ->all();
        if ($monitorArticleIds === []) {
            return [[], []];
        }

        $articles = Article::query()
            ->with([
                'syncedRemoteDistributions:id,article_id,distribution_channel_id,remote_url,updated_at',
                'syncedRemoteDistributions.channel:id,name,domain',
            ])
            ->whereKey(array_values(array_unique($monitorArticleIds)))
            ->get(['id', 'title', 'slug', 'status'])
            ->keyBy('id');

        $monitorSources = [];
        foreach ($monitorArticleIds as $monitorId => $articleId) {
            $article = $articles->get($articleId);
            $monitorSources[$monitorId] = $article ? $this->sourceResolver->forArticle($article) : [];
        }

        return [$monitorArticleIds, $monitorSources];
    }

    /**
     * @param  array<int, int>  $monitorArticleIds
     * @param  array<int, list<array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>>  $monitorSources
     * @return list<array{key:string,label:string,host:string,url:string,article_count:int,sample_count:int,citation_count:int,platform_count:int,last_cited_at:mixed}>
     */
    private function sourceRows(array $monitorArticleIds, array $monitorSources, Carbon $since): array
    {
        $rows = [];
        $monitorWebsites = [];
        $sourceWebsites = [];
        foreach ($monitorSources as $monitorId => $sources) {
            foreach ($sources as $source) {
                $websiteKey = $this->websiteKey($source);
                if ($websiteKey === '') {
                    continue;
                }

                $sourceWebsites[(string) $source['key']] = $websiteKey;
                $monitorWebsites[$monitorId][$websiteKey] = true;
                $rows[$websiteKey] ??= [
                    'key' => $websiteKey,
                    'label' => $source['label'],
                    'host' => $source['host'],
                    'url' => $source['url'],
                    'article_ids' => [],
                    'sample_count' => 0,
                    'citation_count' => 0,
                    'platforms' => [],
                    'last_cited_at' => null,
                ];
                $rows[$websiteKey]['article_ids'][$monitorArticleIds[$monitorId]] = true;
            }
        }

        $results = AiExposureResult::query()
            ->with('run:id,ai_exposure_monitor_id')
            ->where('status', AiExposureResult::STATUS_SUCCEEDED)
            ->where('checked_at', '>=', $since)
            ->lazyById(500, column: 'id');

        foreach ($results as $result) {
            $monitorId = (int) ($result->run?->ai_exposure_monitor_id ?? 0);
            if (! isset($monitorArticleIds[$monitorId])) {
                continue;
            }
            foreach (array_keys($monitorWebsites[$monitorId] ?? []) as $websiteKey) {
                $rows[$websiteKey]['sample_count']++;
            }

            $citedWebsites = [];
            foreach ((array) $result->matched_sources as $matchedSource) {
                $sourceKey = (string) ($matchedSource['key'] ?? '');
                $websiteKey = $sourceWebsites[$sourceKey] ?? $this->websiteKey((array) $matchedSource);
                if ($websiteKey === '' || ! isset($rows[$websiteKey])) {
                    continue;
                }
                $citedWebsites[$websiteKey] = true;
            }
            foreach (array_keys($citedWebsites) as $websiteKey) {
                $rows[$websiteKey]['citation_count']++;
                $rows[$websiteKey]['platforms'][(string) $result->platform] = true;
                if ($rows[$websiteKey]['last_cited_at'] === null || $result->checked_at?->greaterThan($rows[$websiteKey]['last_cited_at'])) {
                    $rows[$websiteKey]['last_cited_at'] = $result->checked_at;
                }
            }
        }

        return collect($rows)
            ->map(function (array $row): array {
                $row['article_count'] = count($row['article_ids']);
                $row['platform_count'] = count($row['platforms']);
                unset($row['article_ids'], $row['platforms']);

                return $row;
            })
            ->sortByDesc('citation_count')
            ->values()
            ->all();
    }

    /** @param array{host?:mixed} $source */
    private function websiteKey(array $source): string
    {
        $host = strtolower(trim((string) ($source['host'] ?? '')));

        return $host === '' ? '' : 'host:'.$host;
    }
}
