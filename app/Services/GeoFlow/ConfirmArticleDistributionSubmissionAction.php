<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmArticleDistributionSubmissionAction
{
    public function canConfirm(ArticleDistribution $distribution): bool
    {
        $channel = $distribution->channel;
        if (! $distribution->article
            || ! $channel instanceof DistributionChannel
            || ! $channel->isBrowserRunner()
            || (string) $distribution->status !== 'failed'
            || (string) $distribution->action !== 'publish'
            || filled($distribution->remote_url)) {
            return false;
        }

        $config = $channel->resolvedBrowserRunnerConfig();

        return in_array($config['browser_platform'] ?? '', ['toutiao', 'sohu'], true)
            && ($config['browser_publish_mode'] ?? '') === 'publish';
    }

    public function execute(
        int $distributionId,
        Admin $admin,
        ?string $remoteId,
        ?string $managementUrl,
    ): ArticleDistribution {
        if (! $admin->canManageProtectedWorkflows()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use (
            $distributionId,
            $admin,
            $remoteId,
            $managementUrl,
        ): ArticleDistribution {
            $distribution = ArticleDistribution::query()
                ->with(['article', 'channel'])
                ->lockForUpdate()
                ->find($distributionId);

            if (! $distribution || ! $distribution->article || ! $distribution->channel) {
                $this->fail(__('admin.distribution.message.job_not_found'));
            }

            $channel = $distribution->channel;
            $config = $channel->resolvedBrowserRunnerConfig();
            $platform = (string) ($config['browser_platform'] ?? '');
            $normalizedRemoteId = trim((string) $remoteId);
            $normalizedManagementUrl = trim((string) $managementUrl);
            $evidenceText = $platform === 'toutiao' ? '审核中' : '已发布';
            $this->validateEvidence(
                $platform,
                $normalizedRemoteId,
                $normalizedManagementUrl,
                $distribution,
            );

            $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
            $existingConfirmation = is_array($remoteMeta['manual_submission_confirmation'] ?? null)
                ? $remoteMeta['manual_submission_confirmation']
                : null;
            if ($existingConfirmation !== null) {
                $this->assertIdempotentReplay(
                    $distribution,
                    $existingConfirmation,
                    $platform,
                    $normalizedRemoteId,
                    $normalizedManagementUrl,
                );

                return $distribution;
            }
            if (! $this->canConfirm($distribution)) {
                $this->fail(__('admin.distribution.submission_confirmation.validation.not_confirmable'));
            }

            $confirmedAt = now();
            $previousStatus = (string) $distribution->status;
            $previousAttemptCount = (int) $distribution->attempt_count;
            $confirmation = [
                'article_distribution_id' => (int) $distribution->id,
                'confirmed_by_admin_id' => (int) $admin->id,
                'confirmed_at' => $confirmedAt->toIso8601String(),
                'previous_status' => $previousStatus,
                'previous_attempt_count' => $previousAttemptCount,
                'source' => 'admin_manual',
                'platform' => $platform,
                'evidence_text' => $evidenceText,
            ];
            if ($platform === 'toutiao') {
                $confirmation['remote_id'] = $normalizedRemoteId;
                $confirmation['management_url'] = $normalizedManagementUrl;
            }

            $remoteMeta = array_replace($remoteMeta, [
                'platform' => $platform,
                'account_id' => (string) ($config['browser_account_id'] ?? ''),
                'transport' => 'admin_manual',
                'runner_status' => 'reviewing',
                'evidence_source' => 'admin_confirmed_reviewing',
                'evidence_text' => $evidenceText,
                'manual_submission_confirmation' => $confirmation,
            ]);

            $distribution->forceFill([
                'status' => 'synced',
                'remote_id' => $platform === 'toutiao' ? $normalizedRemoteId : null,
                'remote_url' => null,
                'remote_meta' => $remoteMeta,
                'last_error_message' => null,
                'next_retry_at' => null,
            ])->save();

            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $distribution->distribution_channel_id,
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'level' => 'info',
                'event' => 'distribution.manual_submission_confirmed',
                'message' => __('admin.distribution.submission_confirmation.audit_message'),
                'context' => [
                    'event' => 'distribution.manual_submission_confirmed',
                    'platform' => $platform,
                    'remote_id' => $platform === 'toutiao' ? $normalizedRemoteId : null,
                    'management_url' => $platform === 'toutiao' ? $normalizedManagementUrl : null,
                    'evidence_text' => $evidenceText,
                    'previous_status' => $previousStatus,
                    'previous_attempt_count' => $previousAttemptCount,
                    'confirmed_by_admin_id' => (int) $admin->id,
                    'source' => 'admin_manual',
                ],
                'created_at' => $confirmedAt,
            ]);

            return $distribution->refresh()->load(['article', 'channel']);
        }, 3);
    }

    private function validateEvidence(
        string $platform,
        string $remoteId,
        string $managementUrl,
        ArticleDistribution $distribution,
    ): void {
        if ($platform === 'sohu') {
            if ($remoteId !== '' || $managementUrl !== '' || filled($distribution->remote_id)) {
                $this->fail(__('admin.distribution.submission_confirmation.validation.sohu_fields'));
            }

            return;
        }
        if ($platform !== 'toutiao'
            || preg_match('/\A[0-9]{10,30}\z/', $remoteId) !== 1
            || (filled($distribution->remote_id) && (string) $distribution->remote_id !== $remoteId)
            || ! $this->validToutiaoManagementUrl($managementUrl, $remoteId)) {
            $this->fail(__('admin.distribution.submission_confirmation.validation.toutiao_evidence'));
        }
    }

    private function validToutiaoManagementUrl(string $managementUrl, string $remoteId): bool
    {
        $parts = parse_url($managementUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) !== 'mp.toutiao.com'
            || (string) ($parts['path'] ?? '') !== '/profile_v4/graphic/preview'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            return false;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return count($query) === 1
            && is_string($query['pgc_id'] ?? null)
            && hash_equals($remoteId, $query['pgc_id']);
    }

    /**
     * @param  array<string,mixed>  $confirmation
     */
    private function assertIdempotentReplay(
        ArticleDistribution $distribution,
        array $confirmation,
        string $platform,
        string $remoteId,
        string $managementUrl,
    ): void {
        $sameToutiaoEvidence = $platform !== 'toutiao'
            || ((string) ($confirmation['remote_id'] ?? '') === $remoteId
                && (string) ($confirmation['management_url'] ?? '') === $managementUrl
                && (string) $distribution->remote_id === $remoteId);
        if ((string) $distribution->status !== 'synced'
            || filled($distribution->remote_url)
            || ($distribution->remote_meta['runner_status'] ?? null) !== 'reviewing'
            || ($confirmation['platform'] ?? null) !== $platform
            || ! $sameToutiaoEvidence) {
            $this->fail(__('admin.distribution.submission_confirmation.validation.existing_confirmation'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['submission' => $message]);
    }
}
