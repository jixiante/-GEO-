<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleDuplicateScan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ArticleDuplicateDetector
{
    public const ALGORITHM_VERSION = 'cjk-3gram-v1';

    /**
     * @return array{status:string,max_similarity:float,matched_article_id:?int,matches:array<int,array{article_id:int,title:string,similarity:float,exact:bool}>,content_hash:string,corpus_hash:string,algorithm_version:string}
     */
    public function scan(string $content, ?int $excludeArticleId = null): array
    {
        $normalized = $this->normalizeVisibleText($content);
        $contentHash = hash('sha256', $normalized);

        $warningThreshold = $this->warningThreshold();
        $blockThreshold = $this->blockThreshold();
        $maxMatches = max(1, (int) config('geoflow.duplicate_detection.max_matches', 5));
        $sourceVector = $this->tokenFrequencies($normalized);
        $bestSimilarity = 0.0;
        $bestArticleId = null;
        $matches = [];
        $corpusContext = hash_init('sha256');

        foreach ($this->candidateQuery($excludeArticleId)->cursor() as $candidate) {
            $candidateNormalized = $this->normalizeVisibleText((string) $candidate->content);
            $candidateHash = hash('sha256', $candidateNormalized);
            hash_update($corpusContext, (int) $candidate->id."\0".$candidateHash."\0".(string) $candidate->title."\0");
            if ($candidateNormalized === '') {
                continue;
            }

            if (! (bool) config('geoflow.duplicate_detection.enabled', true) || $normalized === '') {
                continue;
            }

            $exact = hash_equals($contentHash, $candidateHash);
            $similarity = $exact
                ? 1.0
                : $this->cosineSimilarity($sourceVector, $this->tokenFrequencies($candidateNormalized));

            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestArticleId = (int) $candidate->id;
            }

            if ($similarity < $warningThreshold) {
                continue;
            }

            $matches[] = [
                'article_id' => (int) $candidate->id,
                'title' => (string) $candidate->title,
                'similarity' => round($similarity, 5),
                'exact' => $exact,
            ];
        }

        usort($matches, static fn (array $left, array $right): int => $right['similarity'] <=> $left['similarity']);
        $matches = array_slice($matches, 0, $maxMatches);

        return [
            'status' => $bestSimilarity >= $blockThreshold ? 'blocked' : ($bestSimilarity >= $warningThreshold ? 'warning' : 'clean'),
            'max_similarity' => round($bestSimilarity, 5),
            'matched_article_id' => $bestArticleId,
            'matches' => $matches,
            'content_hash' => $contentHash,
            'corpus_hash' => hash_final($corpusContext),
            'algorithm_version' => $this->algorithmVersion(),
        ];
    }

    public function record(Article $article, string $trigger, ?int $adminId = null): ArticleDuplicateScan
    {
        $result = $this->scan((string) $article->content, (int) $article->getKey());

        return $article->duplicateScans()->create([
            ...$result,
            'trigger' => $trigger,
            'admin_id' => $adminId,
            'scanned_at' => now(),
        ]);
    }

    public function isFresh(Article $article, ArticleDuplicateScan $scan): bool
    {
        return (int) $scan->article_id === (int) $article->getKey()
            && hash_equals((string) $scan->content_hash, $this->contentHash((string) $article->content))
            && is_string($scan->corpus_hash)
            && hash_equals($scan->corpus_hash, $this->corpusHash((int) $article->getKey()))
            && hash_equals((string) $scan->algorithm_version, $this->algorithmVersion());
    }

    public function contentHash(string $content): string
    {
        return hash('sha256', $this->normalizeVisibleText($content));
    }

    public function corpusHash(?int $excludeArticleId = null): string
    {
        $context = hash_init('sha256');

        foreach ($this->candidateQuery($excludeArticleId)->cursor() as $candidate) {
            hash_update(
                $context,
                (int) $candidate->id."\0".$this->contentHash((string) $candidate->content)."\0".(string) $candidate->title."\0",
            );
        }

        return hash_final($context);
    }

    public function normalizeVisibleText(string $content): string
    {
        if (class_exists(\Normalizer::class)) {
            $content = \Normalizer::normalize($content, \Normalizer::FORM_KC) ?: $content;
        }

        $html = Str::markdown($content, [
            // 这里只解析为纯文本，不输出 HTML；允许原始标签可保留标签内的可见正文。
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = mb_convert_case($visible, MB_CASE_FOLD, 'UTF-8');

        return preg_replace('/[\p{Z}\p{P}\p{S}\p{C}]+/u', '', $visible) ?? $visible;
    }

    /** @return array<string, int> */
    private function tokenFrequencies(string $normalized): array
    {
        $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $length = count($characters);
        if ($length === 0) {
            return [];
        }

        $size = min(3, $length);
        $frequencies = [];

        for ($offset = 0; $offset <= $length - $size; $offset++) {
            $token = implode('', array_slice($characters, $offset, $size));
            $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /** @param array<string, int> $left @param array<string, int> $right */
    private function cosineSimilarity(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $dot = 0.0;
        foreach ($left as $token => $frequency) {
            $dot += $frequency * ($right[$token] ?? 0);
        }
        if ($dot === 0.0) {
            return 0.0;
        }

        $leftMagnitude = sqrt((float) array_sum(array_map(static fn (int $value): int => $value * $value, $left)));
        $rightMagnitude = sqrt((float) array_sum(array_map(static fn (int $value): int => $value * $value, $right)));

        return $leftMagnitude > 0.0 && $rightMagnitude > 0.0
            ? min(1.0, $dot / ($leftMagnitude * $rightMagnitude))
            : 0.0;
    }

    private function warningThreshold(): float
    {
        return max(0.0, min(1.0, (float) config('geoflow.duplicate_detection.warning_threshold', 0.85)));
    }

    private function blockThreshold(): float
    {
        return max($this->warningThreshold(), min(1.0, (float) config('geoflow.duplicate_detection.block_threshold', 0.95)));
    }

    private function algorithmVersion(): string
    {
        $settings = [
            'enabled' => (bool) config('geoflow.duplicate_detection.enabled', true),
            'warning_threshold' => $this->warningThreshold(),
            'block_threshold' => $this->blockThreshold(),
            'candidate_limit' => max(0, (int) config('geoflow.duplicate_detection.candidate_limit', 0)),
            'max_matches' => max(1, (int) config('geoflow.duplicate_detection.max_matches', 5)),
        ];

        return self::ALGORITHM_VERSION.'-'.substr(hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** @return Builder<Article> */
    private function candidateQuery(?int $excludeArticleId): Builder
    {
        $candidateLimit = max(0, (int) config('geoflow.duplicate_detection.candidate_limit', 0));
        $query = Article::query()
            ->select(['id', 'title', 'content'])
            ->when($excludeArticleId !== null, fn ($builder) => $builder->whereKeyNot($excludeArticleId));

        return $candidateLimit > 0
            ? $query->orderByDesc('id')->limit($candidateLimit)
            : $query->orderBy('id');
    }
}
