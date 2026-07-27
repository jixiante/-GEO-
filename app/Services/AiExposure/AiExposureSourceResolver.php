<?php

namespace App\Services\AiExposure;

use App\Models\Article;

class AiExposureSourceResolver
{
    /**
     * @return list<array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>
     */
    public function forArticle(Article $article): array
    {
        $sources = [];

        if ($article->status === 'published' && trim((string) $article->slug) !== '') {
            $this->addSource($sources, [
                'label' => (string) config('app.name', 'GEOFlow'),
                'url' => route('site.article', ['slug' => $article->slug]),
                'channel_id' => null,
                'type' => 'local',
            ]);
        }

        $distributions = $article->relationLoaded('syncedRemoteDistributions')
            ? $article->syncedRemoteDistributions
            : $article->syncedRemoteDistributions()
                ->with('channel:id,name,domain')
                ->get(['id', 'article_id', 'distribution_channel_id', 'remote_url', 'updated_at']);

        foreach ($distributions as $distribution) {
            $url = trim((string) $distribution->remote_url);
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            $label = trim((string) ($distribution->channel?->name ?? ''));

            $this->addSource($sources, [
                'label' => $label !== '' ? $label : $host,
                'url' => $url,
                'channel_id' => (int) $distribution->distribution_channel_id,
                'type' => 'distribution',
            ]);
        }

        return array_values($sources);
    }

    public function normalizeUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $path = '/'.ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = $this->normalizeQuery((string) ($parts['query'] ?? ''));

        return $scheme.'://'.$host.$port.$path.($query !== '' ? '?'.$query : '');
    }

    private function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $parameters);
        foreach (array_keys($parameters) as $key) {
            $normalizedKey = strtolower((string) $key);
            if (str_starts_with($normalizedKey, 'utm_') || in_array($normalizedKey, ['fbclid', 'gclid'], true)) {
                unset($parameters[$key]);
            }
        }

        ksort($parameters);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>  $sources
     * @param  array{label:string,url:string,channel_id:?int,type:string}  $candidate
     */
    private function addSource(array &$sources, array $candidate): void
    {
        $normalized = $this->normalizeUrl($candidate['url']);
        if ($normalized === '') {
            return;
        }

        $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
        $key = hash('sha256', $normalized);
        $sources[$key] = [
            'key' => $key,
            'label' => $candidate['label'] !== '' ? $candidate['label'] : $host,
            'url' => $candidate['url'],
            'host' => $host,
            'channel_id' => $candidate['channel_id'],
            'type' => $candidate['type'],
        ];
    }
}
