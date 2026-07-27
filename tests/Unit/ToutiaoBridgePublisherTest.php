<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\ToutiaoBridgePublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToutiaoBridgePublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_toutiao_article_through_configured_bridge(): void
    {
        Http::fake([
            'https://toutiao-bridge.example.com/articles' => Http::response([
                'id' => 'tt-article-123',
                'url' => 'https://www.toutiao.com/article/tt-article-123',
            ], 201),
        ]);

        [, $distribution] = $this->makeDistribution();

        $result = app(ToutiaoBridgePublisher::class)->publish($distribution, [
            'event' => 'article.publish',
            'article' => [
                'title' => '点签头条测试',
                'slug' => 'dianqian-toutiao-test',
                'content' => '测试正文',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('tt-article-123', $result['remote_id']);
        $this->assertSame('https://www.toutiao.com/article/tt-article-123', $result['remote_url']);
        $this->assertSame('toutiao', $result['remote_meta']['platform']);
        $this->assertSame('api_bridge', $result['remote_meta']['transport']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://toutiao-bridge.example.com/articles'
                && $request->hasHeader('Authorization', 'Bearer toutiao-token')
                && $request['distribution_target']['platform'] === 'toutiao'
                && $request['distribution_target']['content_type'] === 'article'
                && $request['article']['title'] === '点签头条测试';
        });
    }

    public function test_orchestrator_persists_toutiao_remote_status(): void
    {
        Http::fake([
            'https://toutiao-bridge.example.com/articles' => Http::response([
                'id' => 'tt-article-456',
                'url' => 'https://www.toutiao.com/article/tt-article-456',
            ], 201),
        ]);

        [, $distribution] = $this->makeDistribution();

        app(DistributionOrchestrator::class)->process($distribution);

        $distribution->refresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertSame('tt-article-456', (string) $distribution->remote_id);
        $this->assertSame('https://www.toutiao.com/article/tt-article-456', (string) $distribution->remote_url);
        $this->assertSame('toutiao', $distribution->remote_meta['platform'] ?? null);
        $this->assertSame('api_bridge', $distribution->remote_meta['transport'] ?? null);
    }

    /**
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => '今日头条',
            'domain' => 'www.toutiao.com',
            'endpoint_url' => 'https://toutiao-bridge.example.com',
            'channel_type' => 'toutiao_bridge',
            'channel_config' => [
                'generic_auth_type' => 'bearer',
                'generic_success_statuses' => [200, 201, 202],
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'generic_settings_method' => 'POST',
                'generic_settings_path' => '',
                'generic_remote_id_path' => 'id',
                'generic_remote_url_path' => 'url',
                'generic_payload_wrapper' => 'none',
            ],
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'tt_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('toutiao-token'),
            'status' => 'active',
            'scopes' => ['toutiao.publish'],
        ]);

        $category = Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $author = Author::query()->create(['name' => '点签']);
        $article = Article::query()->create([
            'title' => '点签头条测试',
            'slug' => 'dianqian-toutiao-test',
            'content' => '测试正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'toutiao-test-key',
        ]);

        return [$channel, $distribution];
    }
}
