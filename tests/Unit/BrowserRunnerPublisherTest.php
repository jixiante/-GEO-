<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\BrowserRunnerClient;
use App\Services\GeoFlow\BrowserRunnerPublisher;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserRunnerPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_through_the_local_browser_runner(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'toutiao-123',
                'remote_url' => 'https://www.toutiao.com/article/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('toutiao-123', $result['remote_id']);
        $this->assertSame('local_playwright', $result['remote_meta']['transport']);
        $this->assertSame('toutiao', $result['remote_meta']['platform']);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://example.com/v1/publish'
                && $request->hasHeader('Authorization', 'Bearer runner-pairing-token-123456')
                && $request['platform'] === 'toutiao'
                && $request['account_id'] === 'company_main'
                && $request['idempotency_key'] === 'browser-test-key';
        });
    }

    public function test_it_rejects_a_runner_success_without_trustworthy_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'current_url' => 'https://mp.163.com/#/article-publish',
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $this->expectExceptionMessage('浏览器发布助手未返回可信的发布确认或内容完整性校验');
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_baijiahao_publish_requires_verified_cover_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_reviewing_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Browser publish test', 'content' => 'Body'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_baijiahao_publish_accepts_verified_cover_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_reviewing_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Browser publish test', 'content' => 'Body'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('baijiahao', $result['remote_meta']['platform']);
        $this->assertTrue($result['remote_meta']['cover_verification']['dialog_closed']);
    }

    public function test_it_accepts_a_platform_success_url_without_inventing_a_remote_id(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'platform_success_url',
                    'evidence_text' => null,
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertNull($result['remote_id']);
        $this->assertNull($result['remote_url']);
        $this->assertSame('platform_success_url', $result['remote_meta']['evidence_source']);
    }

    public function test_it_accepts_a_verified_simulation_without_marking_it_published(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'simulated',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'simulation_complete',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('toutiao', 'simulate');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器模拟发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('simulated', $result['status']);
        $this->assertNull($result['remote_id']);
        $this->assertSame('simulation_complete', $result['remote_meta']['evidence_source']);
        Http::assertSent(fn ($request): bool => $request['publish_mode'] === 'simulate'
            && $request['idempotency_key'] === 'browser-test-key:simulate');
    }

    public function test_orchestrator_persists_simulation_without_a_remote_publication(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'simulated',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'simulation_complete',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('toutiao', 'simulate');

        app(DistributionOrchestrator::class)->process($distribution);

        $distribution->refresh();
        $this->assertSame('simulated', $distribution->status);
        $this->assertNull($distribution->remote_id);
        $this->assertNull($distribution->remote_url);
        $this->assertSame('simulation_complete', $distribution->remote_meta['evidence_source'] ?? null);
    }

    public function test_it_surfaces_manual_action_responses_without_retrying(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => false,
                'code' => 'manual_action_required',
                'message' => '账号登录已失效',
            ], 409),
        ]);
        [, $distribution] = $this->makeDistribution();

        $this->expectExceptionMessage('浏览器发布需要人工处理：账号登录已失效');
        app(BrowserRunnerClient::class)->publish($distribution, ['article' => ['title' => '测试']]);
    }

    public function test_health_request_includes_platform_account_and_token(): void
    {
        Http::fake([
            'https://example.com/v1/health*' => Http::response(['ok' => true, 'enabled' => true]),
        ]);
        [$channel] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('browser_runner', $result['channel_type']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/health')
            && str_contains($request->url(), 'platform=toutiao')
            && str_contains($request->url(), 'account_id=company_main'));
    }

    /**
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(string $platform = 'toutiao', string $publishMode = 'publish'): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => '公司头条浏览器',
            'domain' => 'www.toutiao.com',
            'endpoint_url' => 'https://example.com',
            'channel_type' => 'browser_runner',
            'channel_config' => [
                'browser_platform' => $platform,
                'browser_account_id' => 'company_main',
                'browser_publish_mode' => $publishMode,
                'browser_timeout_seconds' => 180,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'browser_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('runner-pairing-token-123456'),
            'status' => 'active',
            'scopes' => ['browser.publish'],
        ]);
        $category = Category::query()->create(['name' => '科技', 'slug' => 'technology']);
        $author = Author::query()->create(['name' => '点签']);
        $article = Article::query()->create([
            'title' => '浏览器发布测试',
            'slug' => 'browser-publish-test',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'browser-test-key',
        ]);

        return [$channel->load('activeSecret'), $distribution->load('channel.activeSecret')];
    }
}
