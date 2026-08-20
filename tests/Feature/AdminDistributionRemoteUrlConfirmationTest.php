<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Services\AiExposure\AiExposureSourceResolver;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDistributionRemoteUrlConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const TOUTIAO_URL = 'https://www.toutiao.com/article/7667028309171126819/';

    private const TOUTIAO_PGC_ID = '7669646768556818996';

    private const TOUTIAO_MANAGEMENT_URL = 'https://mp.toutiao.com/profile_v4/graphic/preview?pgc_id=7669646768556818996';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_super_admin_can_confirm_toutiao_url_and_make_it_an_ai_exposure_source(): void
    {
        [$article, $channel, $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.remote-url.edit', ['distributionId' => $distribution->id]))
            ->assertOk()
            ->assertSee('name="remote_url"', false)
            ->assertSee(__('admin.distribution.remote_url_confirmation.confirm_label'));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));

        $distribution->refresh();
        $this->assertSame('synced', $distribution->status);
        $this->assertSame('publish', $distribution->action);
        $this->assertSame(self::TOUTIAO_URL, $distribution->remote_url);
        $this->assertSame('7667028309171126819', $distribution->remote_id);
        $this->assertSame(2, $distribution->attempt_count);
        $this->assertNull($distribution->last_error_message);
        $this->assertNull($distribution->next_retry_at);
        $this->assertSame('kept', $distribution->remote_meta['diagnostic']);
        $this->assertSame($admin->id, $distribution->remote_meta['manual_remote_url_confirmation']['confirmed_by_admin_id']);
        $this->assertSame('failed', $distribution->remote_meta['manual_remote_url_confirmation']['previous_status']);
        $this->assertSame('admin_manual', $distribution->remote_meta['manual_remote_url_confirmation']['source']);

        $log = DistributionLog::query()->where('event', 'distribution.remote_url_confirmed')->sole();
        $this->assertSame($distribution->id, $log->article_distribution_id);
        $this->assertSame($admin->id, $log->context['confirmed_by_admin_id']);
        $this->assertSame(self::TOUTIAO_URL, $log->context['remote_url']);

        $toutiaoSource = collect(app(AiExposureSourceResolver::class)->forArticle($article->refresh()))
            ->firstWhere('host', 'www.toutiao.com');
        $this->assertIsArray($toutiaoSource);
        $this->assertSame(self::TOUTIAO_URL, $toutiaoSource['url']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));
        $this->assertSame(1, DistributionLog::query()->where('event', 'distribution.remote_url_confirmed')->count());

        app(ProcessArticleDistributionJob::class, ['distributionId' => $distribution->id])
            ->handle(app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class));
        $this->assertSame(2, $distribution->refresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_super_admin_can_confirm_toutiao_reviewing_submission_without_creating_a_public_source(): void
    {
        [$article, $channel, $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');
        $channel->forceFill([
            'channel_config' => [
                'browser_platform' => 'toutiao',
                'browser_account_id' => 'dianqian_main',
                'browser_publish_mode' => 'publish',
            ],
        ])->save();

        $submissionEditUrl = route('admin.distribution.article.submission.edit', [
            'distributionId' => $distribution->id,
        ]);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.jobs'))
            ->assertOk()
            ->assertSee($submissionEditUrl, false)
            ->assertSee(__('admin.distribution.button.confirm_submission'));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => $channel->id]))
            ->assertOk()
            ->assertSee($submissionEditUrl, false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.submission.edit', ['distributionId' => $distribution->id]))
            ->assertOk()
            ->assertSee('name="remote_id"', false)
            ->assertSee('name="management_url"', false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'remote_id' => self::TOUTIAO_PGC_ID,
                'management_url' => self::TOUTIAO_MANAGEMENT_URL,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));

        $distribution->refresh();
        $this->assertSame('synced', $distribution->status);
        $this->assertSame(self::TOUTIAO_PGC_ID, $distribution->remote_id);
        $this->assertNull($distribution->remote_url);
        $this->assertSame(2, $distribution->attempt_count);
        $this->assertNull($distribution->last_error_message);
        $this->assertNull($distribution->next_retry_at);
        $this->assertSame('kept', $distribution->remote_meta['diagnostic']);
        $this->assertSame('toutiao', $distribution->remote_meta['platform']);
        $this->assertSame('dianqian_main', $distribution->remote_meta['account_id']);
        $this->assertSame('admin_manual', $distribution->remote_meta['transport']);
        $this->assertSame('reviewing', $distribution->remote_meta['runner_status']);
        $this->assertSame('admin_confirmed_reviewing', $distribution->remote_meta['evidence_source']);
        $this->assertSame('审核中', $distribution->remote_meta['evidence_text']);
        $confirmation = $distribution->remote_meta['manual_submission_confirmation'];
        $this->assertSame($distribution->id, $confirmation['article_distribution_id']);
        $this->assertSame($admin->id, $confirmation['confirmed_by_admin_id']);
        $this->assertSame('failed', $confirmation['previous_status']);
        $this->assertSame(2, $confirmation['previous_attempt_count']);
        $this->assertSame(self::TOUTIAO_MANAGEMENT_URL, $confirmation['management_url']);

        $this->assertSame(1, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());
        $this->assertCount(0, collect(app(AiExposureSourceResolver::class)->forArticle($article->refresh()))
            ->where('type', 'remote'));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'remote_id' => self::TOUTIAO_PGC_ID,
                'management_url' => self::TOUTIAO_MANAGEMENT_URL,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));
        $this->assertSame(1, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());

        $differentRemoteId = '7669646768556818997';
        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'remote_id' => $differentRemoteId,
                'management_url' => 'https://mp.toutiao.com/profile_v4/graphic/preview?pgc_id='.$differentRemoteId,
                'confirmed' => '1',
            ])
            ->assertSessionHasErrors('submission');
        $this->assertSame(self::TOUTIAO_PGC_ID, $distribution->refresh()->remote_id);
        $this->assertSame(1, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());

        app(ProcessArticleDistributionJob::class, ['distributionId' => $distribution->id])
            ->handle(app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class));
        $this->assertSame(2, $distribution->refresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_standard_admin_cannot_confirm_reviewing_submission(): void
    {
        [, $channel, $distribution] = $this->fixtures();
        $channel->forceFill([
            'channel_config' => [
                'browser_platform' => 'toutiao',
                'browser_account_id' => 'dianqian_main',
                'browser_publish_mode' => 'publish',
            ],
        ])->save();
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.submission.edit', ['distributionId' => $distribution->id]))
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'remote_id' => self::TOUTIAO_PGC_ID,
                'management_url' => self::TOUTIAO_MANAGEMENT_URL,
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->assertSame('failed', $distribution->refresh()->status);
        $this->assertSame(0, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());
    }

    public function test_reviewing_submission_confirmation_rejects_invalid_toutiao_evidence_and_unsafe_states(): void
    {
        [, $channel, $distribution] = $this->fixtures();
        $channel->forceFill([
            'channel_config' => [
                'browser_platform' => 'toutiao',
                'browser_account_id' => 'dianqian_main',
                'browser_publish_mode' => 'publish',
            ],
        ])->save();
        $admin = $this->admin('super_admin');
        $invalidEvidence = [
            ['short', self::TOUTIAO_MANAGEMENT_URL],
            [self::TOUTIAO_PGC_ID, 'http://mp.toutiao.com/profile_v4/graphic/preview?pgc_id='.self::TOUTIAO_PGC_ID],
            [self::TOUTIAO_PGC_ID, 'https://example.com/profile_v4/graphic/preview?pgc_id='.self::TOUTIAO_PGC_ID],
            [self::TOUTIAO_PGC_ID, 'https://user:password@mp.toutiao.com/profile_v4/graphic/preview?pgc_id='.self::TOUTIAO_PGC_ID],
            [self::TOUTIAO_PGC_ID, 'https://mp.toutiao.com/profile_v4/graphic/preview?pgc_id=7669646768556818997'],
            [self::TOUTIAO_PGC_ID, 'https://mp.toutiao.com/profile_v4/graphic/publish?pgc_id='.self::TOUTIAO_PGC_ID],
        ];

        foreach ($invalidEvidence as [$remoteId, $managementUrl]) {
            $this->actingAs($admin, 'admin')
                ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                    'remote_id' => $remoteId,
                    'management_url' => $managementUrl,
                    'confirmed' => '1',
                ])
                ->assertSessionHasErrors();
            $this->assertSame('failed', $distribution->refresh()->status);
        }

        foreach (['queued', 'sending', 'simulated'] as $status) {
            $distribution->forceFill(['status' => $status, 'action' => 'publish'])->save();
            $this->actingAs($admin, 'admin')
                ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                    'remote_id' => self::TOUTIAO_PGC_ID,
                    'management_url' => self::TOUTIAO_MANAGEMENT_URL,
                    'confirmed' => '1',
                ])
                ->assertSessionHasErrors('submission');
            $this->assertSame($status, $distribution->refresh()->status);
        }

        $distribution->forceFill(['status' => 'failed', 'action' => 'delete'])->save();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'remote_id' => self::TOUTIAO_PGC_ID,
                'management_url' => self::TOUTIAO_MANAGEMENT_URL,
                'confirmed' => '1',
            ])
            ->assertSessionHasErrors('submission');

        $this->assertSame(0, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_super_admin_can_confirm_sohu_reviewing_submission_without_losing_existing_audit_meta(): void
    {
        [$article, $channel, $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');
        $channel->forceFill([
            'name' => '搜狐号浏览器自动发布',
            'domain' => 'mp.sohu.com',
            'channel_config' => [
                'browser_platform' => 'sohu',
                'browser_account_id' => 'dianqian_main',
                'browser_publish_mode' => 'publish',
            ],
        ])->save();
        $distribution->forceFill([
            'remote_meta' => [
                'diagnostic' => 'kept',
                'plain_source_names_approval' => ['approved' => true, 'payload_hash' => str_repeat('a', 64)],
            ],
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.submission.edit', ['distributionId' => $distribution->id]))
            ->assertOk()
            ->assertSee('已发布')
            ->assertDontSee('name="management_url"', false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.submission.update', ['distributionId' => $distribution->id]), [
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));

        $distribution->refresh();
        $this->assertSame('synced', $distribution->status);
        $this->assertNull($distribution->remote_id);
        $this->assertNull($distribution->remote_url);
        $this->assertSame('reviewing', $distribution->remote_meta['runner_status']);
        $this->assertSame('已发布', $distribution->remote_meta['evidence_text']);
        $this->assertSame(
            str_repeat('a', 64),
            $distribution->remote_meta['plain_source_names_approval']['payload_hash'],
        );
        $this->assertCount(0, collect(app(AiExposureSourceResolver::class)->forArticle($article->refresh()))
            ->where('type', 'remote'));
        $this->assertSame(1, DistributionLog::query()->where('event', 'distribution.manual_submission_confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_super_admin_can_confirm_sohu_public_article_url(): void
    {
        [$article, $channel, $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');
        $sohuUrl = 'https://www.sohu.com/a/1056276274_121766836';

        $channel->forceFill([
            'name' => '搜狐号浏览器自动发布',
            'domain' => 'mp.sohu.com',
            'channel_config' => [
                'browser_platform' => 'sohu',
                'browser_account_id' => 'default',
                'browser_publish_mode' => 'publish',
            ],
        ])->save();
        $distribution->forceFill(['status' => 'synced'])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.remote-url.edit', ['distributionId' => $distribution->id]))
            ->assertOk()
            ->assertSee('www.sohu.com');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => $sohuUrl,
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.distribution.show', ['channelId' => $channel->id]));

        $distribution->refresh();
        $this->assertSame($sohuUrl, $distribution->remote_url);
        $this->assertSame('1056276274_121766836', $distribution->remote_id);

        $sohuSource = collect(app(AiExposureSourceResolver::class)->forArticle($article->refresh()))
            ->firstWhere('host', 'www.sohu.com');
        $this->assertIsArray($sohuSource);
        $this->assertSame($sohuUrl, $sohuSource['url']);
    }

    public function test_standard_admin_cannot_open_or_submit_remote_url_confirmation(): void
    {
        [, , $distribution] = $this->fixtures();
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.remote-url.edit', ['distributionId' => $distribution->id]))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->assertSame('failed', $distribution->refresh()->status);
    }

    public function test_remote_url_confirmation_rejects_invalid_or_unrelated_urls(): void
    {
        [, , $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');
        $invalidUrls = [
            'javascript:alert(1)',
            'file:///tmp/article.html',
            'https://example.com/article/7667028309171126819/',
            'https://user:password@www.toutiao.com/article/7667028309171126819/',
            'https://www.toutiao.com/',
            'https://www.toutiao.com/article/not-a-number/',
            'https://www.toutiao.com/article/'.str_repeat('1', 480).'/',
        ];

        foreach ($invalidUrls as $invalidUrl) {
            $this->actingAs($admin, 'admin')
                ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                    'remote_url' => $invalidUrl,
                    'confirmed' => '1',
                ])
                ->assertSessionHasErrors('remote_url');

            $this->assertSame('failed', $distribution->refresh()->status);
            $this->assertNull($distribution->remote_url);
        }

        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
            ])
            ->assertSessionHasErrors('confirmed');

        Http::assertNothingSent();
    }

    public function test_remote_url_confirmation_rejects_unsafe_distribution_states_and_overwrites(): void
    {
        [, , $distribution] = $this->fixtures();
        $admin = $this->admin('super_admin');

        foreach (['queued', 'sending', 'simulated'] as $status) {
            $distribution->forceFill(['status' => $status, 'action' => 'publish', 'remote_url' => null])->save();

            $this->actingAs($admin, 'admin')
                ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                    'remote_url' => self::TOUTIAO_URL,
                    'confirmed' => '1',
                ])
                ->assertSessionHasErrors('remote_url');
            $this->assertSame($status, $distribution->refresh()->status);
        }

        $distribution->forceFill(['status' => 'failed', 'action' => 'delete', 'remote_url' => null])->save();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
                'confirmed' => '1',
            ])
            ->assertSessionHasErrors('remote_url');

        $distribution->forceFill([
            'status' => 'synced',
            'action' => 'publish',
            'remote_url' => 'https://www.toutiao.com/article/7000000000000000000/',
        ])->save();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.distribution.article.remote-url.update', ['distributionId' => $distribution->id]), [
                'remote_url' => self::TOUTIAO_URL,
                'confirmed' => '1',
            ])
            ->assertSessionHasErrors('remote_url');

        $this->assertSame('https://www.toutiao.com/article/7000000000000000000/', $distribution->refresh()->remote_url);
        $this->assertSame(0, DistributionLog::query()->where('event', 'distribution.remote_url_confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_jobs_table_only_shows_confirmation_link_for_eligible_jobs(): void
    {
        [$article, $channel, $eligible] = $this->fixtures();
        $queued = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'update',
            'status' => 'queued',
            'idempotency_key' => 'queued-remote-url-confirmation-test',
        ]);

        $response = $this->actingAs($this->admin('super_admin'), 'admin')
            ->get(route('admin.distribution.jobs'))
            ->assertOk()
            ->assertSee(__('admin.distribution.button.confirm_remote_url'));

        $html = (string) $response->getContent();
        $this->assertStringContainsString(
            route('admin.distribution.article.remote-url.edit', ['distributionId' => $eligible->id]),
            $html,
        );
        $this->assertStringNotContainsString(
            route('admin.distribution.article.remote-url.edit', ['distributionId' => $queued->id]),
            $html,
        );
    }

    /** @return array{Article, DistributionChannel, ArticleDistribution} */
    private function fixtures(): array
    {
        $category = Category::query()->create([
            'name' => 'AI Search',
            'slug' => 'ai-search',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
            'email' => 'remote-url-confirmation@example.com',
        ]);
        $article = Article::query()->create([
            'title' => '企业如何提升 AI 搜索可见性',
            'slug' => 'improve-ai-search-visibility',
            'excerpt' => 'Article excerpt.',
            'content' => 'Article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '今日头条浏览器自动发布',
            'domain' => 'www.toutiao.com',
            'endpoint_url' => 'http://host.docker.internal:19090',
            'channel_type' => DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER,
            'channel_config' => [
                'browser_platform' => 'toutiao',
                'browser_account_id' => 'default',
                'browser_publish_mode' => 'simulate',
            ],
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'remote_meta' => ['diagnostic' => 'kept'],
            'idempotency_key' => 'remote-url-confirmation-test',
            'attempt_count' => 2,
            'next_retry_at' => now()->addMinutes(5),
            'last_error_message' => 'Outbound request failed.',
        ]);

        return [$article, $channel, $distribution];
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'username' => 'remote_url_'.$role,
            'password' => 'secret-123',
            'email' => 'remote-url-'.$role.'@example.com',
            'display_name' => 'Remote URL '.$role,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
