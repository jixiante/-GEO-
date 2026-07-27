<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfirmArticleDistributionRemoteUrlAction
{
    public function canConfirm(ArticleDistribution $distribution): bool
    {
        return $distribution->article !== null
            && $distribution->channel !== null
            && in_array((string) $distribution->status, ['failed', 'synced'], true)
            && (string) $distribution->action !== 'delete'
            && blank($distribution->remote_url);
    }

    public function execute(int $distributionId, int $adminId, string $remoteUrl): ArticleDistribution
    {
        return DB::transaction(function () use ($distributionId, $adminId, $remoteUrl): ArticleDistribution {
            $distribution = ArticleDistribution::query()
                ->with(['article', 'channel'])
                ->lockForUpdate()
                ->find($distributionId);

            if (! $distribution || ! $distribution->article || ! $distribution->channel) {
                throw ValidationException::withMessages([
                    'remote_url' => __('admin.distribution.message.job_not_found'),
                ]);
            }

            $remoteUrl = trim($remoteUrl);
            $remoteId = $this->validateAndExtractRemoteId($distribution->channel, $remoteUrl);
            $status = (string) $distribution->status;
            $existingUrl = trim((string) $distribution->remote_url);

            if ((string) $distribution->action === 'delete') {
                $this->fail('admin.distribution.remote_url_confirmation.validation.delete_action');
            }
            if (! in_array($status, ['failed', 'synced'], true)) {
                $this->fail('admin.distribution.remote_url_confirmation.validation.status');
            }
            if ($existingUrl !== '' && ! $this->sameUrl($existingUrl, $remoteUrl)) {
                $this->fail('admin.distribution.remote_url_confirmation.validation.existing_url');
            }
            if ($status === 'synced' && $existingUrl !== '') {
                return $distribution;
            }

            $confirmedAt = now();
            $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
            $remoteMeta['manual_remote_url_confirmation'] = [
                'confirmed_by_admin_id' => $adminId,
                'confirmed_at' => $confirmedAt->toIso8601String(),
                'previous_status' => $status,
                'source' => 'admin_manual',
            ];

            $distribution->forceFill([
                'status' => 'synced',
                'remote_url' => $remoteUrl,
                'remote_id' => filled($distribution->remote_id) ? $distribution->remote_id : $remoteId,
                'remote_meta' => $remoteMeta,
                'last_error_message' => null,
                'next_retry_at' => null,
            ])->save();

            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $distribution->distribution_channel_id,
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'level' => 'info',
                'event' => 'distribution.remote_url_confirmed',
                'message' => __('admin.distribution.remote_url_confirmation.audit_message'),
                'context' => [
                    'event' => 'distribution.remote_url_confirmed',
                    'remote_url' => $remoteUrl,
                    'previous_status' => $status,
                    'confirmed_by_admin_id' => $adminId,
                    'source' => 'admin_manual',
                ],
                'created_at' => $confirmedAt,
            ]);

            return $distribution->refresh()->load(['article', 'channel']);
        }, 3);
    }

    private function validateAndExtractRemoteId(DistributionChannel $channel, string $remoteUrl): ?string
    {
        $parts = parse_url($remoteUrl);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            $this->fail('admin.distribution.remote_url_confirmation.validation.url');
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            $this->fail('admin.distribution.remote_url_confirmation.validation.url');
        }

        $channelHost = $this->channelHost($channel);
        if ($channelHost === '' || ($host !== $channelHost && ! Str::endsWith($host, '.'.$channelHost))) {
            $this->fail('admin.distribution.remote_url_confirmation.validation.host');
        }

        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        if ($path === '' || $path === '/') {
            $this->fail('admin.distribution.remote_url_confirmation.validation.article_path');
        }

        if ($this->isToutiaoChannel($channel, $host)) {
            if (preg_match('#^/article/([0-9]+)/?$#', $path, $matches) !== 1) {
                $this->fail('admin.distribution.remote_url_confirmation.validation.toutiao_path');
            }

            return $matches[1];
        }

        return preg_match('#/([0-9]+)/?$#', $path, $matches) === 1 ? $matches[1] : null;
    }

    private function channelHost(DistributionChannel $channel): string
    {
        $domain = trim((string) $channel->domain);
        $candidate = Str::startsWith($domain, ['http://', 'https://']) ? $domain : 'https://'.$domain;

        return Str::lower(rtrim((string) (parse_url($candidate, PHP_URL_HOST) ?? ''), '.'));
    }

    private function isToutiaoChannel(DistributionChannel $channel, string $host): bool
    {
        return $channel->isToutiaoBridge()
            || ($channel->isBrowserRunner() && $channel->resolvedBrowserRunnerConfig()['browser_platform'] === 'toutiao')
            || $host === 'toutiao.com'
            || Str::endsWith($host, '.toutiao.com');
    }

    private function sameUrl(string $first, string $second): bool
    {
        return rtrim($first, '/') === rtrim($second, '/');
    }

    private function fail(string $translationKey): never
    {
        throw ValidationException::withMessages([
            'remote_url' => __($translationKey),
        ]);
    }
}
