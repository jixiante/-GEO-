<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use RuntimeException;

class BrowserRunnerPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly BrowserRunnerClient $client,
        private readonly ToutiaoCoverGenerationService $toutiaoCoverGenerator,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        return array_replace($this->client->health($channel), [
            'channel_type' => DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER,
            'platform' => $channel->resolvedBrowserRunnerConfig()['browser_platform'],
        ]);
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $prepared = $this->toutiaoCoverGenerator->prepare($distribution, $payload);
        $result = $this->normalizeResult($distribution, $this->client->publish($distribution, $prepared['payload']));
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? $result['remote_meta'] : [];
        $result['remote_meta'] = array_replace($remoteMeta, [
            'cover_generation' => $prepared['meta'],
        ]);

        return $result;
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->normalizeResult($distribution, $this->client->update($distribution, $payload));
    }

    public function delete(ArticleDistribution $distribution): array
    {
        return $this->normalizeResult($distribution, $this->client->delete($distribution));
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return [
            'ok' => true,
            'skipped' => true,
            'message' => '浏览器平台渠道不支持站点外观设置同步。',
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function normalizeResult(ArticleDistribution $distribution, array $result): array
    {
        $channel = $distribution->channel;
        $config = $channel instanceof DistributionChannel
            ? $channel->resolvedBrowserRunnerConfig()
            : ['browser_platform' => '', 'browser_account_id' => ''];
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? $result['remote_meta'] : [];
        $status = is_scalar($result['status'] ?? null) ? (string) $result['status'] : '';
        $evidenceSource = is_scalar($remoteMeta['evidence_source'] ?? null)
            ? (string) $remoteMeta['evidence_source']
            : '';
        $contentVerification = is_array($remoteMeta['content_verification'] ?? null)
            ? $remoteMeta['content_verification']
            : [];
        $coverVerification = is_array($remoteMeta['cover_verification'] ?? null)
            ? $remoteMeta['cover_verification']
            : [];
        $titleVerified = ($contentVerification['title']['ok'] ?? false) === true;
        $bodyVerified = ($contentVerification['body']['ok'] ?? false) === true;
        $coverRequired = in_array(($config['browser_platform'] ?? ''), ['toutiao', 'baijiahao'], true)
            && $status !== 'draft';
        $coverVerified = ($coverVerification['required'] ?? false) === true
            && ($coverVerification['upload_accepted'] ?? false) === true
            && ($coverVerification['dialog_closed'] ?? false) === true;

        if (($result['ok'] ?? null) !== true
            || ! in_array($status, ['published', 'reviewing', 'draft', 'simulated'], true)
            || ! in_array($evidenceSource, [
                'explicit_success_text',
                'explicit_reviewing_text',
                'explicit_draft_text',
                'simulation_complete',
                'public_url_pattern',
                'platform_success_url',
            ], true)
            || ! $titleVerified
            || ! $bodyVerified
            || ($coverRequired && ! $coverVerified)) {
            throw new RuntimeException('浏览器发布助手未返回可信的发布确认或内容完整性校验，系统已拒绝标记为成功。');
        }

        return array_replace($result, [
            'remote_id' => is_scalar($result['remote_id'] ?? null) ? (string) $result['remote_id'] : null,
            'remote_url' => is_scalar($result['remote_url'] ?? null) ? (string) $result['remote_url'] : null,
            'remote_meta' => array_replace($remoteMeta, [
                'platform' => $config['browser_platform'],
                'account_id' => $config['browser_account_id'],
                'transport' => 'local_playwright',
                'runner_status' => $status,
            ]),
        ]);
    }
}
