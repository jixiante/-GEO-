<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WorkerArticleDuplicateRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_regenerates_duplicate_content_until_it_is_unique(): void
    {
        [$task, $sourceContent] = $this->createTaskAndExistingArticle();
        $uniqueContent = '第二次生成从合同签署身份核验切入，分析证书、印章权限和审计日志，论据与历史文章完全不同。';
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion($sourceContent))
                ->push($this->completion($uniqueContent)),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame($uniqueContent, $article->content);
        $this->assertSame('blocked', data_get($result, 'meta.duplicate_attempts.0.status'));
        $this->assertSame('clean', data_get($result, 'meta.duplicate_attempts.1.status'));
        $this->assertSame('clean', $article->latestDuplicateScan?->status);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $messages = (array) ($request['messages'] ?? []);
            $prompt = (string) data_get($messages, '1.content', data_get($messages, '0.content', ''));

            return str_contains($prompt, '不要只做同义词替换');
        });
    }

    public function test_worker_stops_without_creating_article_after_retry_exhaustion(): void
    {
        config(['geoflow.duplicate_detection.generation_retry_count' => 3]);
        [$task, $sourceContent] = $this->createTaskAndExistingArticle();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion($sourceContent))
                ->push($this->completion($sourceContent))
                ->push($this->completion($sourceContent)),
        ]);

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Expected duplicate retry exhaustion to stop generation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('连续 3 次生成重复或高度相似内容', $exception->getMessage());
        }

        $this->assertSame(1, Article::query()->count());
        $this->assertSame(0, (int) $task->fresh()->created_count);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        Http::assertSentCount(3);
    }

    public function test_worker_sanitizes_generated_content_before_duplicate_scan_and_persistence(): void
    {
        [$task] = $this->createTaskAndExistingArticle();
        $rawContent = "文章从客户签约前的身份核验流程切入 [K1]，并说明证书权限和审计记录。\n\n## 参考来源\n- [K1] 内部知识库\n- https://example.com/source";
        $expectedContent = '文章从客户签约前的身份核验流程切入，并说明证书权限和审计记录。';
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion($rawContent)),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame($expectedContent, $article->content);
        $this->assertSame('clean', data_get($result, 'meta.duplicate_attempts.0.status'));
        $this->assertSame('clean', $article->latestDuplicateScan?->status);
        Http::assertSentCount(1);
    }

    public function test_worker_rejects_generated_content_that_is_empty_after_sanitization(): void
    {
        [$task] = $this->createTaskAndExistingArticle();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(
                $this->completion("[K1]\n\n## References\n- https://example.com/source")
            ),
        ]);

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Expected empty sanitized content to stop article creation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('AI 未生成可用正文', $exception->getMessage());
        }

        $this->assertSame(1, Article::query()->count());
        $this->assertSame(0, (int) $task->fresh()->created_count);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        Http::assertSentCount(1);
    }

    /** @return array{Task, string} */
    private function createTaskAndExistingArticle(): array
    {
        $sourceContent = '历史文章围绕内容中台选题、知识检索、人工审核和多平台分发展开，并说明每日复盘阅读数据的方法。';
        $category = Category::query()->create([
            'name' => 'Worker duplicate',
            'slug' => 'worker-duplicate-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Worker Duplicate',
            'email' => uniqid().'@example.com',
        ]);
        Article::query()->create([
            'title' => '历史文章',
            'slug' => 'worker-duplicate-source-'.uniqid(),
            'excerpt' => '历史摘要',
            'content' => $sourceContent,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $model = AiModel::query()->create([
            'name' => 'Worker duplicate model',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Worker duplicate titles']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '今日企业内容运营方法',
            'keyword' => '内容运营',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $task = Task::query()->create([
            'name' => 'Worker duplicate task',
            'title_library_id' => $library->id,
            'ai_model_id' => $model->id,
            'author_id' => $author->id,
            'fixed_category_id' => $category->id,
            'category_mode' => 'fixed',
            'need_review' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 10,
            'article_limit' => 10,
            'is_loop' => 0,
            'model_selection_mode' => 'fixed',
            'status' => 'active',
            'publish_scope' => 'local_only',
            'created_count' => 0,
            'published_count' => 0,
            'loop_count' => 0,
            'schedule_enabled' => 1,
        ]);

        return [$task, $sourceContent];
    }

    /** @return array<string, mixed> */
    private function completion(string $content): array
    {
        return [
            'model' => 'test-chat-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }
}
