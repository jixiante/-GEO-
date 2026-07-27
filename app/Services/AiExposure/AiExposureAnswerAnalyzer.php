<?php

namespace App\Services\AiExposure;

class AiExposureAnswerAnalyzer
{
    public function __construct(private readonly AiExposureSourceResolver $sourceResolver) {}

    /**
     * @param  list<array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>  $sources
     * @return array{mentioned:bool,cited:bool,cited_urls:list<string>,matched_sources:list<array{key:string,label:string,url:string,host:string,channel_id:?int,type:string}>}
     */
    public function analyze(string $answer, string $articleTitle, array $sources): array
    {
        $citedUrls = $this->extractUrls($answer);
        $normalizedCitations = [];
        foreach ($citedUrls as $url) {
            $normalized = $this->sourceResolver->normalizeUrl($url);
            if ($normalized !== '') {
                $normalizedCitations[$normalized] = true;
            }
        }

        $matchedSources = [];
        foreach ($sources as $source) {
            $normalized = $this->sourceResolver->normalizeUrl((string) $source['url']);
            if ($normalized !== '' && isset($normalizedCitations[$normalized])) {
                $matchedSources[] = $source;
            }
        }

        $normalizedTitle = $this->normalizeText($articleTitle);
        $titleMentioned = $normalizedTitle !== ''
            && str_contains($this->normalizeText($answer), $normalizedTitle);
        $cited = $matchedSources !== [];

        return [
            'mentioned' => $titleMentioned,
            'cited' => $cited,
            'cited_urls' => $citedUrls,
            'matched_sources' => $matchedSources,
        ];
    }

    /** @return list<string> */
    private function extractUrls(string $answer): array
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $answer, $matches);
        $urls = [];

        foreach ($matches[0] ?? [] as $url) {
            $url = rtrim((string) $url, ".,;:!?)]}'\"");
            $url = preg_replace('/[\x{3002}\x{FF0C}\x{FF1B}\x{FF1A}\x{FF01}\x{FF1F}]+$/u', '', $url) ?? $url;
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/\s+/u', '', $value) ?? $value;
    }
}
