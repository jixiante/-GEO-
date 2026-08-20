<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\BrowserRunnerClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DistributionChannelBrowserRunnerConfigTest extends TestCase
{
    public function test_browser_runner_config_rejects_a_missing_platform(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_account_id' => 'company_main',
            'browser_publish_mode' => 'simulate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_platform');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_browser_runner_config_rejects_an_unsupported_platform(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'unknown-platform',
            'browser_account_id' => 'company_main',
            'browser_publish_mode' => 'simulate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_platform');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_browser_runner_config_rejects_a_blank_account_id(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'zhihu',
            'browser_account_id' => '   ',
            'browser_publish_mode' => 'simulate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_account_id');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_browser_runner_config_rejects_a_missing_account_id(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'zhihu',
            'browser_publish_mode' => 'simulate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_account_id');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_browser_runner_config_rejects_a_missing_publish_mode(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'zhihu',
            'browser_account_id' => 'company_main',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_publish_mode');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_browser_runner_config_rejects_an_unsupported_publish_mode(): void
    {
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'zhihu',
            'browser_account_id' => 'company_main',
            'browser_publish_mode' => 'simluate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('browser_publish_mode');

        $channel->resolvedBrowserRunnerConfig();
    }

    public function test_health_rejects_invalid_browser_runner_config_before_http(): void
    {
        Http::fake();
        $channel = $this->browserRunnerChannel([
            'browser_account_id' => 'company_main',
            'browser_publish_mode' => 'simulate',
        ]);

        $exception = null;
        try {
            app(BrowserRunnerClient::class)->health($channel);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('browser_platform', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_publish_rejects_invalid_browser_runner_config_before_http(): void
    {
        Http::fake();
        $channel = $this->browserRunnerChannel([
            'browser_platform' => 'zhihu',
            'browser_account_id' => 'company_main',
        ]);
        $channel->setRelation('activeSecret', null);
        $distribution = new ArticleDistribution([
            'idempotency_key' => 'invalid-browser-config',
        ]);
        $distribution->setRelation('channel', $channel);
        $distribution->setRelation('article', new Article);

        $exception = null;
        try {
            app(BrowserRunnerClient::class)->publish($distribution, [
                'article' => ['title' => '不得发送'],
            ]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('browser_publish_mode', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_unconfigured_non_browser_prototype_keeps_form_defaults(): void
    {
        $config = (new DistributionChannel)->resolvedBrowserRunnerConfig();

        $this->assertSame('toutiao', $config['browser_platform']);
        $this->assertSame('default', $config['browser_account_id']);
        $this->assertSame('publish', $config['browser_publish_mode']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function browserRunnerChannel(array $config): DistributionChannel
    {
        return new DistributionChannel([
            'channel_type' => DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER,
            'channel_config' => $config,
        ]);
    }
}
