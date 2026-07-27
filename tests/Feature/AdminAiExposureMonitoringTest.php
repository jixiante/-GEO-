<?php

namespace Tests\Feature;

use App\Contracts\AiExposure\AiExposureAnswerProvider;
use App\Events\Admin\AiExposureOverviewUpdated;
use App\Jobs\RunAiExposurePlatformCheckJob;
use App\Models\Admin;
use App\Models\AiExposureMonitor;
use App\Models\AiExposurePlatformConfig;
use App\Models\AiExposureResult;
use App\Models\AiExposureRun;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\Admin\AiExposureDashboardService;
use App\Services\AiExposure\AiExposureMonitorRunner;
use App\Services\AiExposure\AiExposureRunDispatcher;
use App\Services\AiExposure\AiExposureSourceResolver;
use Illuminate\Console\Scheduling\Schedule as ScheduleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AdminAiExposureMonitoringTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_lists_all_platforms_and_published_websites(): void
    {
        $fixtures = $this->fixtures();
        $this->monitor($fixtures['article']);

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.index'))
            ->assertOk()
            ->assertSee('AI 回答曝光')
            ->assertSee('DeepSeek')
            ->assertSee('豆包')
            ->assertSee('Kimi')
            ->assertSee('千问')
            ->assertSee('文心一言')
            ->assertSee('智谱')
            ->assertSee('知乎发布站')
            ->assertSee('www.zhihu.com');
    }

    #[Test]
    public function five_minute_frequency_is_available_and_advances_by_five_minutes(): void
    {
        $from = Carbon::parse('2026-07-27 10:00:00');
        $monitor = new AiExposureMonitor(['frequency' => AiExposureMonitor::FREQUENCY_FIVE_MINUTES]);

        $this->assertContains(AiExposureMonitor::FREQUENCY_FIVE_MINUTES, AiExposureMonitor::frequencies());
        $this->assertTrue($monitor->nextScheduledAt($from)?->equalTo($from->copy()->addMinutes(5)));
    }

    #[Test]
    public function the_ai_exposure_scheduler_scans_every_minute_with_distributed_guards(): void
    {
        $event = collect(app(ScheduleManager::class)->events())
            ->first(fn (object $event): bool => str_contains((string) ($event->command ?? ''), 'geoflow:schedule-ai-exposure'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    public function monitor_rows_show_only_terminal_run_totals_and_realtime_targets(): void
    {
        $fixtures = $this->fixtures();
        $monitor = $this->monitor($fixtures['article']);

        foreach ([
            [AiExposureRun::STATUS_COMPLETED, 2, 1],
            [AiExposureRun::STATUS_FAILED, 0, 0],
            [AiExposureRun::STATUS_QUEUED, 9, 9],
        ] as $index => [$status, $mentioned, $cited]) {
            AiExposureRun::query()->create([
                'ai_exposure_monitor_id' => $monitor->id,
                'dispatch_key' => 'test:monitor-totals:'.$index,
                'status' => $status,
                'platform_total' => 2,
                'platform_succeeded' => $status === AiExposureRun::STATUS_COMPLETED ? 2 : 0,
                'mentioned_count' => $mentioned,
                'cited_count' => $cited,
            ]);
        }

        $dashboard = app(AiExposureDashboardService::class)->build(Request::create('/geo_admin/ai-exposure'));
        $row = $dashboard['monitors']->firstWhere('id', $monitor->id);

        $this->assertSame(2, (int) $row->run_count);
        $this->assertSame(2, (int) $row->mentioned_count);
        $this->assertSame(1, (int) $row->cited_count);

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.index'))
            ->assertOk()
            ->assertSee('data-ai-exposure-metric="sample_count"', false)
            ->assertSee('data-ai-exposure-platform="deepseek"', false)
            ->assertSee('data-ai-exposure-monitor="'.$monitor->id.'"', false)
            ->assertSee('data-ai-exposure-monitor-metric="run_count"', false);
    }

    #[Test]
    public function only_active_super_admins_can_subscribe_to_ai_exposure_updates(): void
    {
        $fixtures = $this->fixtures();
        $regularAdmin = Admin::query()->create([
            'username' => 'ai_exposure_regular_admin',
            'password' => 'secret-123',
            'email' => 'ai-exposure-regular@example.com',
            'display_name' => 'AI Exposure Regular Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-admin.ai-exposure'];

        $this->actingAs($regularAdmin, 'admin')
            ->post('/broadcasting/auth', $payload)
            ->assertForbidden();

        $this->actingAs($fixtures['admin'], 'admin')
            ->post('/broadcasting/auth', $payload)
            ->assertOk();
    }

    #[Test]
    public function platform_configuration_requires_a_chat_model_when_enabled(): void
    {
        $fixtures = $this->fixtures();
        $payload = $this->platformPayload();
        $payload['platforms']['deepseek']['enabled'] = 1;

        $this->actingAs($fixtures['admin'], 'admin')
            ->put(route('admin.ai-exposure.platforms.update'), $payload)
            ->assertSessionHasErrors('platforms.deepseek.ai_model_id');

        $payload['platforms']['deepseek']['ai_model_id'] = $fixtures['model']->id;
        $this->actingAs($fixtures['admin'], 'admin')
            ->put(route('admin.ai-exposure.platforms.update'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ai_exposure_platform_configs', [
            'platform' => 'deepseek',
            'ai_model_id' => $fixtures['model']->id,
            'enabled' => 1,
        ]);
    }

    #[Test]
    public function a_manual_run_creates_one_bounded_job_per_enabled_platform(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $this->enablePlatform('zhipu', $fixtures['model']);

        $monitor = $this->monitor($fixtures['article']);

        $this->actingAs($fixtures['admin'], 'admin')
            ->post(route('admin.ai-exposure.monitors.run', ['monitorId' => $monitor->id]))
            ->assertSessionHasNoErrors();

        $run = AiExposureRun::query()->sole();
        $this->assertSame(2, $run->platform_total);
        $this->assertSame(2, $run->results()->count());
        Queue::assertPushed(RunAiExposurePlatformCheckJob::class, 2);
    }

    #[Test]
    public function platform_answers_are_saved_with_mention_and_exact_source_evidence(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $monitor = $this->monitor($fixtures['article']);
        $provider = new FakeAiExposureAnswerProvider([
            'deepseek' => '《企业 GEO 实战指南》给出了完整步骤。来源：https://www.zhihu.com/p/123456',
        ]);
        $this->app->instance(AiExposureAnswerProvider::class, $provider);

        $run = app(AiExposureRunDispatcher::class)->dispatch($monitor);
        $result = $run->results()->sole();
        app(AiExposureMonitorRunner::class)->runResult((int) $result->id);

        $result->refresh();
        $run->refresh();
        $this->assertSame(AiExposureResult::STATUS_SUCCEEDED, $result->status);
        $this->assertTrue($result->mentioned);
        $this->assertTrue($result->cited);
        $this->assertSame('知乎发布站', $result->matched_sources[0]['label']);
        $this->assertSame(AiExposureRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->mentioned_count);
        $this->assertSame(1, $run->cited_count);
        $this->assertSame($monitor->query, $provider->questions['deepseek']);
        $this->assertStringNotContainsString((string) $fixtures['article']->title, $provider->questions['deepseek']);
    }

    #[Test]
    public function completed_platform_checks_broadcast_the_latest_overview(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $monitor = $this->monitor($fixtures['article']);
        $this->app->instance(AiExposureAnswerProvider::class, new FakeAiExposureAnswerProvider([
            'deepseek' => 'Source: https://www.zhihu.com/p/123456',
        ]));
        Event::fake([AiExposureOverviewUpdated::class]);

        $run = app(AiExposureRunDispatcher::class)->dispatch($monitor);
        app(AiExposureMonitorRunner::class)->runResult((int) $run->results()->sole()->id);

        Event::assertDispatched(AiExposureOverviewUpdated::class, function (AiExposureOverviewUpdated $event) use ($monitor): bool {
            return $event->overview['metrics']['sample_count'] === 1
                && $event->overview['platforms']['deepseek']['cited_count'] === 1
                && $event->overview['monitors'][$monitor->id]['run_count'] === 1;
        });
    }

    #[Test]
    public function final_queue_failure_broadcasts_the_terminal_run_count(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $monitor = $this->monitor($fixtures['article']);
        $this->app->instance(AiExposureAnswerProvider::class, new FakeAiExposureAnswerProvider([
            'deepseek' => new RuntimeException('Provider unavailable.'),
        ]));
        Event::fake([AiExposureOverviewUpdated::class]);

        $run = app(AiExposureRunDispatcher::class)->dispatch($monitor);
        $result = $run->results()->sole();
        $job = new RunAiExposurePlatformCheckJob((int) $result->id, (string) $result->platform);

        try {
            $job->handle(app(AiExposureMonitorRunner::class));
            $this->fail('The provider exception should be released to the queue for retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider unavailable.', $exception->getMessage());
            $this->assertDatabaseHas('ai_exposure_results', [
                'id' => $result->id,
                'status' => AiExposureResult::STATUS_RUNNING,
            ]);
            $job->failed($exception);
        }

        Event::assertDispatched(AiExposureOverviewUpdated::class, function (AiExposureOverviewUpdated $event) use ($monitor): bool {
            return $event->overview['metrics']['sample_count'] === 0
                && $event->overview['monitors'][$monitor->id]['run_count'] === 1;
        });
    }

    #[Test]
    public function one_platform_failure_keeps_other_platform_evidence_and_marks_the_run_partial(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $this->enablePlatform('zhipu', $fixtures['model']);
        $monitor = $this->monitor($fixtures['article']);
        $this->app->instance(AiExposureAnswerProvider::class, new FakeAiExposureAnswerProvider([
            'deepseek' => '来源：https://www.zhihu.com/p/123456',
            'zhipu' => new RuntimeException('Provider unavailable.'),
        ]));

        $run = app(AiExposureRunDispatcher::class)->dispatch($monitor);
        foreach ($run->results()->orderBy('id')->get() as $result) {
            $job = new RunAiExposurePlatformCheckJob((int) $result->id, (string) $result->platform);

            try {
                $job->handle(app(AiExposureMonitorRunner::class));
            } catch (RuntimeException $exception) {
                $job->failed($exception);
            }
        }

        $run->refresh();
        $this->assertSame(AiExposureRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->platform_succeeded);
        $this->assertSame(1, $run->cited_count);
        $this->assertDatabaseHas('ai_exposure_results', ['platform' => 'zhipu', 'status' => 'failed']);
    }

    #[Test]
    public function the_scheduler_advances_the_slot_and_does_not_enqueue_it_twice(): void
    {
        Queue::fake();
        $fixtures = $this->fixtures();
        $this->enablePlatform('deepseek', $fixtures['model']);
        $monitor = $this->monitor($fixtures['article'], AiExposureMonitor::FREQUENCY_DAILY);
        $monitor->forceFill(['next_run_at' => now()->subMinute()])->save();

        $this->artisan('geoflow:schedule-ai-exposure')->assertSuccessful();
        $this->artisan('geoflow:schedule-ai-exposure')->assertSuccessful();

        $this->assertSame(1, AiExposureRun::query()->count());
        $this->assertTrue($monitor->fresh()->next_run_at->isFuture());
        Queue::assertPushed(RunAiExposurePlatformCheckJob::class, 1);
    }

    #[Test]
    public function answer_evidence_is_html_escaped(): void
    {
        $fixtures = $this->fixtures();
        $monitor = $this->monitor($fixtures['article']);
        $run = AiExposureRun::query()->create([
            'ai_exposure_monitor_id' => $monitor->id,
            'dispatch_key' => 'test:evidence',
            'status' => AiExposureRun::STATUS_COMPLETED,
            'platform_total' => 1,
            'platform_succeeded' => 1,
        ]);
        $result = AiExposureResult::query()->create([
            'ai_exposure_run_id' => $run->id,
            'platform' => 'deepseek',
            'ai_model_id' => $fixtures['model']->id,
            'status' => AiExposureResult::STATUS_SUCCEEDED,
            'answer_text' => '<script>alert(1)</script>',
            'cited_urls' => [],
            'matched_sources' => [],
            'checked_at' => now(),
        ]);

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.results.show', ['resultId' => $result->id]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    public function not_exposed_filter_requires_neither_a_mention_nor_a_citation(): void
    {
        $fixtures = $this->fixtures();
        $monitor = $this->monitor($fixtures['article']);
        $run = AiExposureRun::query()->create([
            'ai_exposure_monitor_id' => $monitor->id,
            'dispatch_key' => 'test:independent-filter',
            'status' => AiExposureRun::STATUS_COMPLETED,
            'platform_total' => 2,
            'platform_succeeded' => 2,
        ]);
        $citationOnly = AiExposureResult::query()->create([
            'ai_exposure_run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => AiExposureResult::STATUS_SUCCEEDED,
            'mentioned' => false,
            'cited' => true,
            'checked_at' => now(),
        ]);
        $notExposed = AiExposureResult::query()->create([
            'ai_exposure_run_id' => $run->id,
            'platform' => 'zhipu',
            'status' => AiExposureResult::STATUS_SUCCEEDED,
            'mentioned' => false,
            'cited' => false,
            'checked_at' => now(),
        ]);

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.index', ['exposure' => 'not_exposed']))
            ->assertOk()
            ->assertSee(route('admin.ai-exposure.results.show', ['resultId' => $notExposed->id]), false)
            ->assertDontSee(route('admin.ai-exposure.results.show', ['resultId' => $citationOnly->id]), false);
    }

    #[Test]
    public function evidence_displays_independent_exposure_states_and_failures(): void
    {
        $fixtures = $this->fixtures();
        $monitor = $this->monitor($fixtures['article']);
        $run = AiExposureRun::query()->create([
            'ai_exposure_monitor_id' => $monitor->id,
            'dispatch_key' => 'test:evidence-states',
            'status' => AiExposureRun::STATUS_PARTIAL,
            'platform_total' => 2,
            'platform_succeeded' => 1,
        ]);
        $exposed = AiExposureResult::query()->create([
            'ai_exposure_run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => AiExposureResult::STATUS_SUCCEEDED,
            'mentioned' => true,
            'cited' => true,
            'answer_text' => 'Evidence answer.',
            'checked_at' => now(),
        ]);
        $failed = AiExposureResult::query()->create([
            'ai_exposure_run_id' => $run->id,
            'platform' => 'zhipu',
            'status' => AiExposureResult::STATUS_FAILED,
            'error_message' => 'Provider unavailable.',
            'checked_at' => now(),
        ]);

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.results.show', ['resultId' => $exposed->id]))
            ->assertOk()
            ->assertSeeText(__('admin.ai_exposure.result.mentioned'))
            ->assertSeeText(__('admin.ai_exposure.result.cited'))
            ->assertDontSeeText(__('admin.ai_exposure.result.not_exposed'));

        $this->actingAs($fixtures['admin'], 'admin')
            ->get(route('admin.ai-exposure.results.show', ['resultId' => $failed->id]))
            ->assertOk()
            ->assertSeeText(__('admin.ai_exposure.result.failed'))
            ->assertDontSeeText(__('admin.ai_exposure.result.not_exposed'));
    }

    #[Test]
    public function published_site_statistics_group_exact_article_urls_by_website(): void
    {
        $fixtures = $this->fixtures();
        $firstArticle = $fixtures['article'];
        $secondArticle = Article::query()->create([
            'title' => '第二篇 GEO 实战指南',
            'slug' => 'second-geo-guide',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $firstArticle->category_id,
            'author_id' => $firstArticle->author_id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $channel = DistributionChannel::query()->sole();
        ArticleDistribution::query()->create([
            'article_id' => $secondArticle->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://www.zhihu.com/p/654321',
            'idempotency_key' => 'ai-exposure-second-zhihu',
        ]);

        $firstMonitor = $this->monitor($firstArticle);
        $secondMonitor = $this->monitor($secondArticle);
        $resolver = app(AiExposureSourceResolver::class);
        $firstZhihuSource = collect($resolver->forArticle($firstArticle))->firstWhere('host', 'www.zhihu.com');

        foreach ([
            [$firstMonitor, 'deepseek', [$firstZhihuSource]],
            [$secondMonitor, 'zhipu', []],
        ] as $index => [$monitor, $platform, $matchedSources]) {
            $run = AiExposureRun::query()->create([
                'ai_exposure_monitor_id' => $monitor->id,
                'dispatch_key' => 'test:site-grouping:'.$index,
                'status' => AiExposureRun::STATUS_COMPLETED,
                'platform_total' => 1,
                'platform_succeeded' => 1,
            ]);
            AiExposureResult::query()->create([
                'ai_exposure_run_id' => $run->id,
                'platform' => $platform,
                'status' => AiExposureResult::STATUS_SUCCEEDED,
                'cited' => $matchedSources !== [],
                'matched_sources' => $matchedSources,
                'checked_at' => now(),
            ]);
        }

        $dashboard = app(AiExposureDashboardService::class)->build(Request::create('/geo_admin/ai-exposure'));
        $zhihuRows = collect($dashboard['sourceRows'])->where('host', 'www.zhihu.com')->values();

        $this->assertCount(1, $zhihuRows);
        $this->assertSame(2, $zhihuRows[0]['article_count']);
        $this->assertSame(2, $zhihuRows[0]['sample_count']);
        $this->assertSame(1, $zhihuRows[0]['citation_count']);
        $this->assertSame(1, $zhihuRows[0]['platform_count']);
    }

    /** @return array<string, mixed> */
    private function fixtures(): array
    {
        $admin = Admin::query()->create([
            'username' => 'ai_exposure_admin',
            'password' => 'secret-123',
            'email' => 'ai-exposure@example.com',
            'display_name' => 'AI Exposure Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $author = Author::query()->create(['name' => '曝光作者', 'slug' => 'ai-exposure-author', 'status' => 'active']);
        $category = Category::query()->create(['name' => '曝光分类', 'slug' => 'ai-exposure-category', 'status' => 'active']);
        $article = Article::query()->create([
            'title' => '企业 GEO 实战指南',
            'slug' => 'enterprise-geo-guide',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'original_keyword' => '企业 GEO 怎么做',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '知乎发布站',
            'domain' => 'www.zhihu.com',
            'endpoint_url' => 'https://www.zhihu.com',
            'status' => 'active',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://www.zhihu.com/p/123456',
            'idempotency_key' => 'ai-exposure-article-zhihu',
        ]);
        $model = AiModel::query()->create([
            'name' => 'DeepSeek Exposure',
            'api_key' => 'test-key',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'status' => 'active',
        ]);

        return compact('admin', 'article', 'model');
    }

    private function monitor(Article $article, string $frequency = AiExposureMonitor::FREQUENCY_MANUAL): AiExposureMonitor
    {
        return AiExposureMonitor::query()->create([
            'article_id' => $article->id,
            'query' => '企业如何提升 AI 搜索可见性？',
            'frequency' => $frequency,
            'status' => AiExposureMonitor::STATUS_ACTIVE,
        ]);
    }

    private function enablePlatform(string $platform, AiModel $model): void
    {
        AiExposurePlatformConfig::query()->create([
            'platform' => $platform,
            'ai_model_id' => $model->id,
            'enabled' => true,
        ]);
    }

    /** @return array{platforms: array<string, array{enabled:int, ai_model_id:?int}>} */
    private function platformPayload(): array
    {
        $platforms = [];
        foreach (['deepseek', 'doubao', 'kimi', 'qwen', 'ernie', 'zhipu'] as $platform) {
            $platforms[$platform] = ['enabled' => 0, 'ai_model_id' => null];
        }

        return ['platforms' => $platforms];
    }
}

class FakeAiExposureAnswerProvider implements AiExposureAnswerProvider
{
    /** @var array<string, string> */
    public array $questions = [];

    /** @param array<string, string|RuntimeException> $answers */
    public function __construct(private readonly array $answers) {}

    public function answer(AiModel $model, string $platform, string $question): string
    {
        $this->questions[$platform] = $question;
        $answer = $this->answers[$platform] ?? new RuntimeException('No fake answer configured.');
        if ($answer instanceof RuntimeException) {
            throw $answer;
        }

        return $answer;
    }
}
