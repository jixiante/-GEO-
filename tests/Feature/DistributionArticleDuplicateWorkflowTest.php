<?php

namespace Tests\Feature;

use App\Exceptions\ArticleDuplicateGateException;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionArticleDuplicateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_exact_duplicate_is_not_enqueued_for_distribution(): void
    {
        $content = '这篇文章用于验证进入多平台队列之前必须完成正文查重，重复稿不能继续发送。';
        $this->createArticle($content, '历史文章');
        [$target] = $this->createDistributionArticle($content);

        app(DistributionOrchestrator::class)->enqueueForArticle($target);

        $this->assertDatabaseCount('article_distributions', 0);
        $this->assertSame('blocked', $target->fresh()->latestDuplicateScan?->status);
        $this->assertSame('distribution_enqueue', $target->fresh()->latestDuplicateScan?->trigger);
        Queue::assertNothingPushed();
    }

    public function test_distribution_send_rechecks_article_edited_after_enqueue(): void
    {
        [$target, , $channel] = $this->createDistributionArticle('入队时这是一篇内容独立的文章。');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($target);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        $distribution = ArticleDistribution::query()->firstOrFail();

        $duplicateContent = '排队之后编辑成历史文章的相同正文，发送前复检必须阻止绕过审核。';
        $this->createArticle($duplicateContent, '发送前新增的历史文章');
        $target->update(['content' => $duplicateContent]);
        Http::fake();

        try {
            $orchestrator->process($distribution);
            $this->fail('Expected duplicate content to be rejected before sending.');
        } catch (ArticleDuplicateGateException $exception) {
            $this->assertSame('blocked', $exception->duplicateStatus);
        }

        $this->assertSame('queued', $distribution->fresh()->status);
        $this->assertSame('distribution_send', $target->fresh()->latestDuplicateScan?->trigger);
        Http::assertNothingSent();
    }

    /** @return array{Article, Task, DistributionChannel} */
    private function createDistributionArticle(string $content): array
    {
        $task = Task::query()->create([
            'name' => 'Duplicate distribution task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'local_and_distribution',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Duplicate distribution target',
            'domain' => 'duplicate-target.example.com',
            'endpoint_url' => 'https://duplicate-target.example.com',
            'status' => 'active',
        ]);
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
        $article = $this->createArticle($content, '待分发文章', [
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return [$article, $task, $channel];
    }

    /** @param array<string, mixed> $attributes */
    private function createArticle(string $content, string $title, array $attributes = []): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'distribution-duplicate'], ['name' => 'Distribution duplicate']);
        $author = Author::query()->firstOrCreate(['email' => 'distribution-duplicate@example.com'], ['name' => 'Distribution Duplicate']);

        return Article::query()->create(array_merge([
            'title' => $title,
            'slug' => 'distribution-duplicate-'.uniqid(),
            'excerpt' => '查重测试摘要',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $attributes));
    }
}
