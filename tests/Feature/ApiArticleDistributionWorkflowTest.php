<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ApiArticleDistributionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Author $author;

    private Category $category;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        if (! Schema::hasTable('article_reviews')) {
            Schema::create('article_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('admin_id')->constrained('admins');
                $table->string('review_status', 20);
                $table->text('review_note')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
        $this->admin = Admin::query()->create([
            'username' => 'api-distribution-admin',
            'password' => 'secret-123',
            'email' => 'api-distribution@example.com',
            'display_name' => 'API Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->author = Author::query()->create([
            'name' => 'API Distribution Author',
            'email' => 'api-distribution-author@example.com',
        ]);
        $this->category = Category::query()->create([
            'name' => 'API Distribution Category',
            'slug' => 'api-distribution-category',
        ]);
        $this->token = $this->admin
            ->createToken('api-article-distribution', ['articles:write', 'articles:publish'])
            ->plainTextToken;
    }

    public function test_taskless_publish_enqueues_only_explicit_active_channels_in_request_order(): void
    {
        $first = $this->createChannel('First');
        $second = $this->createChannel('Second');
        $unselected = $this->createChannel('Unselected');
        $article = $this->createApprovedDraft('ordered-explicit-channels');

        $this->publish($article, [
            'distribution_channel_ids' => [(int) $second->id, (int) $first->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $article->refresh();
        $this->assertNull($article->task_id);
        $this->assertSame('published', $article->status);
        $this->assertSame(
            [(int) $second->id, (int) $first->id],
            ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->orderBy('id')
                ->pluck('distribution_channel_id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );
        $this->assertDatabaseMissing('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $unselected->id,
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);
    }

    public function test_publish_without_channel_ids_remains_local_only(): void
    {
        $this->createChannel('Available');
        $article = $this->createApprovedDraft('local-only-when-omitted');

        $this->publish($article)
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_inactive_channel_is_rejected_before_article_status_changes(): void
    {
        $inactive = $this->createChannel('Inactive', 'paused');
        $article = $this->createApprovedDraft('inactive-channel');

        $this->publish($article, [
            'distribution_channel_ids' => [(int) $inactive->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_channel_is_rejected_before_article_status_changes(): void
    {
        $article = $this->createApprovedDraft('missing-channel');

        $this->publish($article, [
            'distribution_channel_ids' => [999999],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_channel_ids_must_be_positive_distinct_integers(): void
    {
        $channel = $this->createChannel('Duplicate');
        $article = $this->createApprovedDraft('invalid-channel-shape');

        $this->publish($article, [
            'distribution_channel_ids' => [(int) $channel->id, (int) $channel->id, 0],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_repeating_same_idempotent_publish_does_not_duplicate_distributions(): void
    {
        $first = $this->createChannel('Idempotent First');
        $second = $this->createChannel('Idempotent Second');
        $article = $this->createApprovedDraft('idempotent-explicit-channels');
        $payload = [
            'distribution_channel_ids' => [(int) $first->id, (int) $second->id],
        ];
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'X-Idempotency-Key' => 'taskless-explicit-publish',
        ];

        $firstResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/publish", $payload);
        $secondResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/articles/{$article->id}/publish", $payload);

        $firstResponse->assertOk();
        $secondResponse->assertOk()->assertExactJson($firstResponse->json());
        $this->assertSame(2, ArticleDistribution::query()->where('article_id', (int) $article->id)->count());
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);
    }

    public function test_unkeyed_enqueue_failure_returns_stable_error_and_rolls_back_publication(): void
    {
        $channel = $this->createChannel('Failed Enqueue');
        $article = $this->createApprovedDraft('unkeyed-enqueue-failure');
        $this->mock(DistributionOrchestrator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enqueueForArticleChannels')
                ->once()
                ->andThrow(new RuntimeException('queue unavailable'));
        });

        $this->publish($article, [
            'distribution_channel_ids' => [(int) $channel->id],
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'distribution_enqueue_failed');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_channel_state_change_during_publish_rolls_back_publication(): void
    {
        $channel = $this->createChannel('Race Channel');
        $article = $this->createApprovedDraft('channel-state-race');
        $this->mock(DistributionOrchestrator::class, function (MockInterface $mock) use ($channel): void {
            $mock->shouldReceive('enqueueForArticleChannels')
                ->once()
                ->andReturnUsing(function () use ($channel): never {
                    DistributionChannel::query()->whereKey($channel->id)->update(['status' => 'paused']);

                    throw new ApiException(
                        'distribution_channels_changed',
                        '指定分发渠道的启用状态已变化，文章发布已回滚',
                        409,
                    );
                });
        });

        $this->publish($article, [
            'distribution_channel_ids' => [(int) $channel->id],
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'distribution_channels_changed');

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('active', $channel->fresh()->status);
        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_synced_same_payload_skips_but_changed_payload_queues_update(): void
    {
        $channel = $this->createChannel('Payload Update');
        $article = $this->createApprovedDraft('payload-update');
        $payload = ['distribution_channel_ids' => [(int) $channel->id]];

        $this->publish($article, $payload)->assertOk();
        $distribution = ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('distribution_channel_id', (int) $channel->id)
            ->firstOrFail();
        $originalHash = (string) $distribution->payload_hash;
        $distribution->forceFill([
            'status' => 'synced',
            'remote_id' => 'remote-payload-update',
            'remote_url' => 'https://payload-update.example.com/article/remote-payload-update',
        ])->save();

        $this->publish($article, $payload)->assertOk();
        $this->assertSame(1, ArticleDistribution::query()->where('article_id', (int) $article->id)->count());
        $this->assertSame('synced', $distribution->fresh()->status);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/articles/{$article->id}", [
                'content' => 'Updated unique safe content that must be sent as a remote update.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'pending');
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/review", [
                'review_status' => 'approved',
                'review_note' => 'Updated content reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'approved');

        $this->publish($article, $payload)->assertOk();

        $updatedDistribution = ArticleDistribution::query()->findOrFail((int) $distribution->id);
        $this->assertSame(1, ArticleDistribution::query()->where('article_id', (int) $article->id)->count());
        $this->assertSame('update', $updatedDistribution->action);
        $this->assertSame('queued', $updatedDistribution->status);
        $this->assertNotSame($originalHash, (string) $updatedDistribution->payload_hash);
        $this->assertSame('remote-payload-update', $updatedDistribution->remote_id);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);
    }

    private function createChannel(string $name, string $status = 'active'): DistributionChannel
    {
        $slug = strtolower(str_replace(' ', '-', $name));

        return DistributionChannel::query()->create([
            'name' => $name,
            'domain' => $slug.'.example.com',
            'endpoint_url' => 'https://'.$slug.'.example.com',
            'status' => $status,
        ]);
    }

    private function createApprovedDraft(string $slug): Article
    {
        return Article::query()->create([
            'title' => 'API distribution article '.$slug,
            'slug' => $slug,
            'content' => 'Unique safe content for '.$slug.'.',
            'excerpt' => 'Safe excerpt for '.$slug.'.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function publish(Article $article, array $payload = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/articles/{$article->id}/publish", $payload);
    }
}
