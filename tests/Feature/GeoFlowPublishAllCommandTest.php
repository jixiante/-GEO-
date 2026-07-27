<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeoFlowPublishAllCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_force_all_queues_every_active_channel_in_pivot_order_despite_round_robin(): void
    {
        $first = $this->createChannel('平台一');
        $second = $this->createChannel('平台二');
        $task = $this->createTask(TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN);
        $task->distributionChannels()->sync([
            (int) $second->id => ['sort_order' => 0],
            (int) $first->id => ['sort_order' => 1],
        ]);
        $article = $this->createArticle($task, '全渠道发布文章', '本文介绍点签在多个内容平台之间依次分发稿件的完整流程。');

        $queuedCount = app(DistributionOrchestrator::class)
            ->enqueueForArticle($article, 'publish', true, true);

        $this->assertSame(2, $queuedCount);
        $this->assertSame(
            [(int) $second->id, (int) $first->id],
            ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->orderBy('id')
                ->pluck('distribution_channel_id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);
        $this->assertSame(0, (int) $task->fresh()->distribution_cursor);
    }

    public function test_one_click_mode_skips_synced_and_in_progress_channels(): void
    {
        $synced = $this->createChannel('已发布平台');
        $sending = $this->createChannel('发布中平台');
        $pending = $this->createChannel('待发布平台');
        $task = $this->createTask();
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [
            (int) $synced->id,
            (int) $sending->id,
            (int) $pending->id,
        ]);
        $article = $this->createArticle($task, '避免重复提交文章', '一键分发需要识别已经发布和正在处理的平台，确保相同稿件不会重复提交。');
        $this->createDistribution($article, $synced, 'synced');
        $this->createDistribution($article, $sending, 'sending');

        $queuedCount = app(DistributionOrchestrator::class)
            ->enqueueForArticle($article, 'publish', true, true);

        $this->assertSame(1, $queuedCount);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $synced->id,
            'status' => 'synced',
        ]);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $sending->id,
            'status' => 'sending',
        ]);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $pending->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_command_fails_with_an_actionable_message_when_task_has_no_active_channels(): void
    {
        $task = $this->createTask();
        $article = $this->createArticle($task, '缺少渠道文章', '这篇文章用于验证系统在没有分发渠道时能够给出清楚的中文配置提示。');

        $this->artisan('geoflow:publish-all', ['articleId' => (int) $article->id])
            ->expectsOutputToContain('尚未关联启用中的分发渠道')
            ->assertExitCode(Command::FAILURE);

        Queue::assertNothingPushed();
    }

    public function test_command_without_an_id_selects_the_latest_distributable_article(): void
    {
        $channel = $this->createChannel('最新稿件平台');
        $task = $this->createTask();
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
        $older = $this->createArticle($task, '较早文章', '较早文章专门讨论企业知识库的归档规范和长期维护制度。');
        $latest = $this->createArticle($task, '最新文章', '最新文章重点介绍内容团队的跨平台投放节奏与审核协作方法。');

        $this->artisan('geoflow:publish-all')
            ->expectsOutputToContain('准备分发文章 #'.(int) $latest->id)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('article_distributions', ['article_id' => (int) $older->id]);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $latest->id,
            'distribution_channel_id' => (int) $channel->id,
            'status' => 'queued',
        ]);
    }

    public function test_preflight_reports_browser_runner_requirement_without_enqueuing(): void
    {
        $channel = $this->createChannel('今日头条', DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER, [
            'browser_platform' => 'toutiao',
        ]);
        $task = $this->createTask();
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
        $article = $this->createArticle($task, '预检查文章', '预检查只核对文章状态和发布渠道，不应该提前创建任何分发队列记录。');

        $this->artisan('geoflow:publish-all', [
            'articleId' => (int) $article->id,
            '--preflight' => true,
        ])
            ->expectsOutput('GEOFLOW_ARTICLE_ID='.(int) $article->id)
            ->expectsOutput('GEOFLOW_BROWSER_RUNNER_REQUIRED=1')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    private function createTask(string $strategy = TaskDistributionChannelSelector::STRATEGY_BROADCAST): Task
    {
        return Task::query()->create([
            'name' => '一键分发测试任务 '.uniqid(),
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'local_and_distribution',
            'distribution_strategy' => $strategy,
        ]);
    }

    /** @param array<string, mixed> $channelConfig */
    private function createChannel(
        string $name,
        string $channelType = DistributionChannel::CHANNEL_TYPE_GEOFLOW_AGENT,
        array $channelConfig = [],
    ): DistributionChannel {
        $key = uniqid();

        return DistributionChannel::query()->create([
            'name' => $name,
            'domain' => $key.'.example.com',
            'endpoint_url' => 'https://'.$key.'.example.com',
            'channel_type' => $channelType,
            'channel_config' => $channelConfig,
            'status' => 'active',
        ]);
    }

    private function createArticle(Task $task, string $title, string $content): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'publish-all'],
            ['name' => '一键分发测试分类'],
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'publish-all@example.com'],
            ['name' => '一键分发测试作者'],
        );

        return Article::query()->create([
            'title' => $title,
            'slug' => 'publish-all-'.uniqid(),
            'excerpt' => '一键分发测试摘要',
            'content' => $content,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function createDistribution(Article $article, DistributionChannel $channel, string $status): ArticleDistribution
    {
        return ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => $status,
            'idempotency_key' => 'publish-all-existing-'.(int) $article->id.'-'.(int) $channel->id,
            'attempt_count' => $status === 'sending' ? 1 : 0,
        ]);
    }
}
