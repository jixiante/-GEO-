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
        $payload = $this->applyApprovedPlatformTitle($distribution, $payload);
        $prepared = $this->toutiaoCoverGenerator->prepare($distribution, $payload);
        $requiresAiDisclosure = ($prepared['payload']['article']['is_ai_generated'] ?? false) === true
            || (int) ($prepared['payload']['article']['is_ai_generated'] ?? 0) === 1;
        $requiresLinkVerification = preg_match(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])https?:\/\//iu',
            (string) ($prepared['payload']['article']['content_html'] ?? ''),
        ) === 1;
        $result = $this->normalizeResult(
            $distribution,
            $this->client->publish($distribution, $prepared['payload']),
            $requiresAiDisclosure,
            $requiresLinkVerification,
            $this->payloadHash($prepared['payload']),
        );
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? $result['remote_meta'] : [];
        $result['remote_meta'] = array_replace($remoteMeta, [
            'cover_generation' => $prepared['meta'],
        ]);

        return $result;
    }

    /**
     * Apply a human-approved title only to this frozen Toutiao distribution.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function applyApprovedPlatformTitle(ArticleDistribution $distribution, array $payload): array
    {
        $meta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
        if (! array_key_exists('platform_title_approval', $meta)) {
            return $payload;
        }

        $approval = is_array($meta['platform_title_approval'] ?? null)
            ? $meta['platform_title_approval']
            : [];
        $distribution->loadMissing('channel');
        $channel = $distribution->channel;
        $platform = $channel instanceof DistributionChannel && $channel->isBrowserRunner()
            ? $channel->resolvedBrowserRunnerConfig()['browser_platform']
            : '';
        $distributionId = $approval['article_distribution_id'] ?? null;
        $approvedTitle = is_string($approval['approved_title'] ?? null)
            ? $approval['approved_title']
            : '';
        $payloadHash = is_string($approval['payload_hash'] ?? null)
            ? $approval['payload_hash']
            : '';
        $currentPayloadHash = $this->payloadHash($payload);
        $frozenPayloadHash = is_string($distribution->payload_hash)
            ? $distribution->payload_hash
            : '';
        $approvedBy = is_string($approval['approved_by'] ?? null)
            ? trim($approval['approved_by'])
            : '';
        $approvedAt = is_string($approval['approved_at'] ?? null)
            ? trim($approval['approved_at'])
            : '';

        if (($approval['approved'] ?? false) !== true
            || ($approval['platform'] ?? null) !== 'toutiao'
            || $platform !== 'toutiao'
            || (string) $distribution->action !== 'publish'
            || ! is_int($distributionId)
            || $distributionId !== (int) $distribution->getKey()
            || $approvedTitle === ''
            || $approvedTitle !== trim($approvedTitle)
            || mb_strlen($approvedTitle, 'UTF-8') > 30
            || preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $frozenPayloadHash) !== 1
            || ! hash_equals($frozenPayloadHash, $payloadHash)
            || ! hash_equals($payloadHash, $currentPayloadHash)
            || $approvedBy === ''
            || $approvedAt === ''
            || ! is_array($payload['article'] ?? null)) {
            throw new RuntimeException('经批准的平台标题与当前头条分发任务不匹配，系统已中止本次操作。');
        }

        $payload['article']['title'] = $approvedTitle;

        return $payload;
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
    private function normalizeResult(
        ArticleDistribution $distribution,
        array $result,
        bool $requiresAiDisclosure = false,
        bool $requiresLinkVerification = false,
        ?string $outgoingPayloadHash = null,
    ): array {
        $channel = $distribution->channel;
        $config = $channel instanceof DistributionChannel
            ? $channel->resolvedBrowserRunnerConfig()
            : ['browser_platform' => '', 'browser_account_id' => ''];
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? $result['remote_meta'] : [];
        $status = is_scalar($result['status'] ?? null) ? (string) $result['status'] : '';
        $publishMode = is_string($config['browser_publish_mode'] ?? null)
            ? $config['browser_publish_mode']
            : '';
        $allowedStatuses = match ($publishMode) {
            'publish' => ['published', 'reviewing'],
            'draft' => ['draft'],
            'simulate' => ['simulated'],
            default => [],
        };
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
        $linksVerified = $this->linksVerified($contentVerification['links'] ?? null)
            || $this->approvedPlainSourceNamesVerified(
                $distribution,
                $config['browser_platform'] ?? '',
                $contentVerification['links'] ?? null,
                $outgoingPayloadHash,
            );
        $coverRequired = in_array(($config['browser_platform'] ?? ''), ['toutiao', 'baijiahao'], true)
            && $publishMode !== 'draft';
        $coverVerified = ($coverVerification['required'] ?? false) === true
            && ($coverVerification['upload_accepted'] ?? false) === true
            && ($coverVerification['dialog_closed'] ?? false) === true;
        $aiDisclosureVerification = is_array($remoteMeta['ai_disclosure_verification'] ?? null)
            ? $remoteMeta['ai_disclosure_verification']
            : [];
        $allowedAiDisclosureOptions = [
            'toutiao' => ['引用AI'],
            'baijiahao' => ['采用AI生成内容'],
            'zhihu' => ['包含 AI 辅助创作', '包含 AI 辅助创作 作者对内容负责'],
            'sohu' => ['包含AI创作内容'],
        ];
        $aiDisclosureOptionVerified = is_string($aiDisclosureVerification['option_text'] ?? null)
            && in_array(
                $aiDisclosureVerification['option_text'],
                $allowedAiDisclosureOptions[$config['browser_platform'] ?? ''] ?? [],
                true,
            );
        $aiDisclosureEvidence = is_array($aiDisclosureVerification['evidence'] ?? null)
            ? $aiDisclosureVerification['evidence']
            : [];
        $aiDisclosureEvidenceAttribute = $aiDisclosureEvidence['attribute'] ?? null;
        $aiDisclosureEvidenceValue = $aiDisclosureEvidence['value'] ?? null;
        $aiDisclosureEvidenceVerified = match ($aiDisclosureEvidenceAttribute) {
            'checked', 'selected' => $aiDisclosureEvidenceValue === true,
            'aria-checked', 'aria-selected' => $aiDisclosureEvidenceValue === 'true',
            'data-state' => in_array($aiDisclosureEvidenceValue, ['checked', 'selected', 'on', 'true'], true),
            'selector_state' => is_string($aiDisclosureEvidenceValue)
                && trim($aiDisclosureEvidenceValue) !== ''
                && (($config['browser_platform'] ?? '') !== 'baijiahao'
                    || $aiDisclosureEvidenceValue === '.one-checkbox-wrapper:has-text("采用AI生成内容") .one-checkbox.one-checkbox-checked'),
            default => false,
        };
        $aiDisclosureVerified = ($aiDisclosureVerification['required'] ?? false) === true
            && ($aiDisclosureVerification['platform'] ?? null) === ($config['browser_platform'] ?? '')
            && ($aiDisclosureVerification['selected'] ?? false) === true
            && $aiDisclosureOptionVerified
            && $aiDisclosureEvidenceVerified;
        $requiredUncheckedOptionsVerified = $this->requiredUncheckedOptionsVerified(
            $config['browser_platform'] ?? '',
            $remoteMeta['required_unchecked_options_verification'] ?? null,
        );
        $requiredUncheckedOptionsRequired = ($config['browser_platform'] ?? '') === 'baijiahao'
            && $publishMode !== 'draft';

        if (($result['ok'] ?? null) !== true
            || ! in_array($status, $allowedStatuses, true)
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
            || ($requiresLinkVerification && ! $linksVerified)
            || ($coverRequired && ! $coverVerified)
            || ($requiresAiDisclosure && ! $aiDisclosureVerified)
            || ($requiredUncheckedOptionsRequired && ! $requiredUncheckedOptionsVerified)) {
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

    private function requiredUncheckedOptionsVerified(string $platform, mixed $verification): bool
    {
        if ($platform !== 'baijiahao') {
            return true;
        }
        if (! is_array($verification)
            || ($verification['required'] ?? false) !== true
            || ($verification['platform'] ?? null) !== 'baijiahao'
            || ($verification['all_unchecked'] ?? false) !== true
            || ! is_array($verification['options'] ?? null)
            || ! array_is_list($verification['options'])) {
            return false;
        }

        $expectedEvidence = [
            '自动生成视频' => '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox:not(.one-checkbox-checked)',
            '自动生成播客' => '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox:not(.one-checkbox-checked)',
        ];
        if (count($verification['options']) !== count($expectedEvidence)) {
            return false;
        }

        foreach ($verification['options'] as $option) {
            if (! is_array($option)) {
                return false;
            }
            $text = is_string($option['text'] ?? null) ? $option['text'] : '';
            $evidence = is_array($option['evidence'] ?? null) ? $option['evidence'] : [];
            if (! array_key_exists($text, $expectedEvidence)
                || ($option['unchecked'] ?? false) !== true
                || ! is_bool($option['changed'] ?? null)
                || ! $this->isVerifiedUncheckedEvidence($evidence, $expectedEvidence[$text])) {
                return false;
            }
            unset($expectedEvidence[$text]);
        }

        return $expectedEvidence === [];
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function isVerifiedUncheckedEvidence(array $evidence, string $expectedSelector): bool
    {
        return match ($evidence['attribute'] ?? null) {
            'selector_state' => ($evidence['value'] ?? null) === $expectedSelector,
            'checked' => ($evidence['value'] ?? null) === false,
            'aria-checked' => ($evidence['value'] ?? null) === 'false',
            'data-state' => in_array($evidence['value'] ?? null, ['unchecked', 'off', 'false'], true),
            'class:one-checkbox-checked' => ($evidence['value'] ?? null) === false,
            default => false,
        };
    }

    private function linksVerified(mixed $verification): bool
    {
        if (! is_array($verification)
            || ($verification['required'] ?? false) !== true
            || ($verification['ok'] ?? false) !== true
            || (int) ($verification['missingCount'] ?? -1) !== 0) {
            return false;
        }

        $expected = array_values(array_filter(
            is_array($verification['expectedUrls'] ?? null) ? $verification['expectedUrls'] : [],
            fn (mixed $url): bool => is_string($url) && preg_match('/^https?:\/\//i', $url) === 1,
        ));
        $actual = array_values(array_filter(
            is_array($verification['actualUrls'] ?? null) ? $verification['actualUrls'] : [],
            fn (mixed $url): bool => is_string($url) && preg_match('/^https?:\/\//i', $url) === 1,
        ));

        return $expected !== []
            && (int) ($verification['expectedCount'] ?? -1) === count($expected)
            && (int) ($verification['matchedCount'] ?? -1) === count($expected)
            && array_diff($expected, $actual) === [];
    }

    private function approvedPlainSourceNamesVerified(
        ArticleDistribution $distribution,
        string $platform,
        mixed $linkVerification,
        ?string $outgoingPayloadHash,
    ): bool {
        $approval = $this->plainSourceNamesApproval(
            $distribution,
            $platform,
            $outgoingPayloadHash,
        );
        if ($approval === null
            || ! is_array($linkVerification)
            || ($linkVerification['required'] ?? false) !== true
            || ($linkVerification['ok'] ?? null) !== false) {
            return false;
        }

        $expectedUrls = $this->httpUrlList($linkVerification['expectedUrls'] ?? null);
        if ($expectedUrls === null
            || $expectedUrls === []
            || ($linkVerification['expectedCount'] ?? null) !== count($expectedUrls)
            || ($linkVerification['actualCount'] ?? null) !== 0
            || ($linkVerification['matchedCount'] ?? null) !== 0
            || ($linkVerification['missingCount'] ?? null) !== count($expectedUrls)
            || ($linkVerification['actualUrls'] ?? null) !== []
            || ($linkVerification['missingUrls'] ?? null) !== $expectedUrls) {
            return false;
        }

        $sourceNames = is_array($linkVerification['plain_source_names'] ?? null)
            ? $linkVerification['plain_source_names']
            : [];
        $expectedNames = $this->plainSourceNameList($sourceNames['expectedNames'] ?? null);
        $actualNames = $this->plainSourceNameList($sourceNames['actualNames'] ?? null);
        $sourcePayloadHash = is_string($sourceNames['payload_hash'] ?? null)
            ? $sourceNames['payload_hash']
            : '';

        return ($sourceNames['ok'] ?? false) === true
            && ($sourceNames['platform'] ?? null) === 'sohu'
            && ($sourceNames['article_distribution_id'] ?? null) === (int) $distribution->getKey()
            && preg_match('/\A[a-f0-9]{64}\z/', $sourcePayloadHash) === 1
            && hash_equals($approval['payload_hash'], $sourcePayloadHash)
            && $expectedNames !== null
            && $expectedNames !== []
            && $actualNames !== null
            && $actualNames === $expectedNames
            && ($sourceNames['expectedCount'] ?? null) === count($expectedNames)
            && ($sourceNames['actualCount'] ?? null) === count($actualNames)
            && ($sourceNames['matchedCount'] ?? null) === count($expectedNames)
            && ($sourceNames['missingCount'] ?? null) === 0
            && ($sourceNames['missingNames'] ?? null) === [];
    }

    /**
     * @return array{payload_hash:string}|null
     */
    private function plainSourceNamesApproval(
        ArticleDistribution $distribution,
        string $platform,
        ?string $outgoingPayloadHash,
    ): ?array {
        if ($platform !== 'sohu') {
            return null;
        }

        $meta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
        $approval = is_array($meta['plain_source_names_approval'] ?? null)
            ? $meta['plain_source_names_approval']
            : [];
        $payloadHash = is_string($approval['payload_hash'] ?? null)
            ? $approval['payload_hash']
            : '';
        $currentPayloadHash = is_string($distribution->payload_hash)
            ? $distribution->payload_hash
            : '';

        if (($approval['approved'] ?? false) !== true
            || ($approval['platform'] ?? null) !== 'sohu'
            || ($approval['article_distribution_id'] ?? null) !== (int) $distribution->getKey()
            || preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $currentPayloadHash) !== 1
            || ! hash_equals($currentPayloadHash, $payloadHash)
            || ! is_string($outgoingPayloadHash)
            || preg_match('/\A[a-f0-9]{64}\z/', $outgoingPayloadHash) !== 1
            || ! hash_equals($payloadHash, $outgoingPayloadHash)
            || ! is_string($approval['approved_by'] ?? null)
            || trim($approval['approved_by']) === ''
            || ! is_string($approval['approved_at'] ?? null)
            || trim($approval['approved_at']) === '') {
            return null;
        }

        return ['payload_hash' => $payloadHash];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
    }

    /**
     * @return list<string>|null
     */
    private function httpUrlList(mixed $urls): ?array
    {
        if (! is_array($urls) || ! array_is_list($urls)) {
            return null;
        }

        foreach ($urls as $url) {
            if (! is_string($url) || preg_match('/\Ahttps?:\/\//i', $url) !== 1) {
                return null;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>|null
     */
    private function plainSourceNameList(mixed $names): ?array
    {
        if (! is_array($names) || ! array_is_list($names)) {
            return null;
        }

        $normalized = [];
        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                return null;
            }
            $normalized[] = trim($name);
        }

        return $normalized;
    }
}
