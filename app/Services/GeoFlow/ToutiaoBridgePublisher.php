<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;

class ToutiaoBridgePublisher implements DistributionPublisherInterface
{
    public function __construct(private readonly GenericHttpApiPublisher $httpPublisher) {}

    public function health(DistributionChannel $channel): array
    {
        return array_replace($this->httpPublisher->health($channel), [
            'channel_type' => DistributionChannel::CHANNEL_TYPE_TOUTIAO_BRIDGE,
            'platform' => 'toutiao',
        ]);
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->withToutiaoMetadata(
            $this->httpPublisher->publish($distribution, $this->platformPayload($payload))
        );
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->withToutiaoMetadata(
            $this->httpPublisher->update($distribution, $this->platformPayload($payload))
        );
    }

    public function delete(ArticleDistribution $distribution): array
    {
        return $this->withToutiaoMetadata($this->httpPublisher->delete($distribution));
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return $this->httpPublisher->syncSiteSettings($channel);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function platformPayload(array $payload): array
    {
        $payload['distribution_target'] = [
            'platform' => 'toutiao',
            'content_type' => 'article',
        ];

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function withToutiaoMetadata(array $result): array
    {
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? $result['remote_meta'] : [];
        $result['remote_meta'] = array_replace($remoteMeta, [
            'platform' => 'toutiao',
            'transport' => 'api_bridge',
        ]);

        return $result;
    }
}
