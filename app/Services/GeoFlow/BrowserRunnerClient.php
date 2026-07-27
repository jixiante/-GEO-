<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class BrowserRunnerClient
{
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function health(DistributionChannel $channel): array
    {
        $config = $channel->resolvedBrowserRunnerConfig();

        return $this->request($channel, 'GET', '/v1/health', [
            'platform' => $config['browser_platform'],
            'account_id' => $config['browser_account_id'],
        ], 15);
    }

    /**
     * @return array<string,mixed>
     */
    public function openLogin(DistributionChannel $channel): array
    {
        $config = $channel->resolvedBrowserRunnerConfig();

        return $this->request($channel, 'POST', '/v1/accounts/login', [
            'platform' => $config['browser_platform'],
            'account_id' => $config['browser_account_id'],
        ], 30);
    }

    /**
     * @return array<string,mixed>
     */
    public function control(DistributionChannel $channel, bool $enabled): array
    {
        return $this->request($channel, 'POST', '/v1/control/'.($enabled ? 'start' : 'stop'), [], 15);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->sendDistributionAction($distribution, 'publish', $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->sendDistributionAction($distribution, 'update', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(ArticleDistribution $distribution): array
    {
        return $this->sendDistributionAction($distribution, 'delete', []);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sendDistributionAction(ArticleDistribution $distribution, string $action, array $payload): array
    {
        $distribution->loadMissing(['channel.activeSecret', 'article']);
        $channel = $distribution->channel;
        if (! $channel instanceof DistributionChannel) {
            throw new RuntimeException('浏览器发布任务缺少分发渠道。');
        }

        $config = $channel->resolvedBrowserRunnerConfig();
        $idempotencyKey = (string) $distribution->idempotency_key;
        if ($config['browser_publish_mode'] === 'simulate') {
            $idempotencyKey .= ':simulate';
        }

        return $this->request($channel, 'POST', '/v1/'.$action, [
            'platform' => $config['browser_platform'],
            'account_id' => $config['browser_account_id'],
            'publish_mode' => $config['browser_publish_mode'],
            'idempotency_key' => $idempotencyKey,
            'remote_id' => (string) ($distribution->remote_id ?? ''),
            'remote_url' => (string) ($distribution->remote_url ?? ''),
            'payload' => $payload,
        ], $config['browser_timeout_seconds']);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function request(DistributionChannel $channel, string $method, string $path, array $data, int $timeout): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret) {
            throw new RuntimeException('浏览器发布助手配对令牌不存在。');
        }

        $plainSecret = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($plainSecret === '') {
            throw new RuntimeException('浏览器发布助手配对令牌无法解密，请在渠道编辑页重新填写。');
        }

        $request = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($plainSecret)
            ->timeout($timeout)
            ->connectTimeout(5);
        $url = rtrim((string) $channel->endpoint_url, '/').$path;
        $response = $this->safeHttp->send($request, $method, $url, $data, $this->jsonMaxBytes());
        $secret->forceFill(['last_used_at' => now()])->save();

        if ($response->failed()) {
            throw new DistributionHttpException($this->failureMessage($response), $response->status());
        }

        $result = $response->json();

        return is_array($result) ? $result : ['ok' => true];
    }

    private function jsonMaxBytes(): int
    {
        return min(2 * 1024 * 1024, (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024));
    }

    private function failureMessage(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json) && is_scalar($json['message'] ?? null)
            ? trim((string) $json['message'])
            : '';
        $code = is_array($json) && is_scalar($json['code'] ?? null)
            ? trim((string) $json['code'])
            : '';

        if ($response->status() === 401) {
            return '浏览器发布助手鉴权失败（HTTP 401），请确认渠道配对令牌与本机 Runner 配置一致。';
        }
        if ($response->status() === 409 || $code === 'manual_action_required') {
            return '浏览器发布需要人工处理：'.($message !== '' ? $message : '请检查登录、验证码或平台风控提示。');
        }

        return '浏览器发布助手请求失败（HTTP '.$response->status().'）'.($message !== '' ? '：'.$message : '。');
    }
}
