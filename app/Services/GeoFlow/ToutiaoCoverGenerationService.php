<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ArticleImage;
use App\Models\DistributionChannel;
use App\Models\Image as ImageModel;
use App\Support\GeoFlow\ImageUrlNormalizer;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Image;
use RuntimeException;
use Throwable;

class ToutiaoCoverGenerationService
{
    public function __construct(private readonly ManagedImageFileService $managedImages) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{payload:array<string,mixed>,meta:array<string,mixed>}
     */
    public function prepare(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing(['article.task', 'channel']);
        $article = $distribution->article;
        $channel = $distribution->channel;

        if (! $article instanceof Article || ! $channel instanceof DistributionChannel) {
            throw new RuntimeException('头条封面生成任务缺少文章或渠道。');
        }

        $channelConfig = $channel->resolvedBrowserRunnerConfig();
        if (! (bool) config('geoflow.toutiao_cover.enabled', true)
            || $channelConfig['browser_platform'] !== 'toutiao'
            || $channelConfig['browser_publish_mode'] === 'draft') {
            return ['payload' => $payload, 'meta' => ['generated' => false, 'reason' => 'not_required']];
        }

        $libraryId = (int) ($article->task?->image_library_id ?? 0);
        if ($libraryId <= 0) {
            return ['payload' => $payload, 'meta' => ['generated' => false, 'reason' => 'image_library_not_configured']];
        }

        $references = $this->referenceImages($article, $libraryId);
        if ($references === []) {
            throw new RuntimeException('任务图片库中没有可用于生成头条封面的有效图片。');
        }

        $coverHash = $this->coverHash($article, $references);
        $cover = $this->cachedCover($article, $libraryId, $coverHash)
            ?? $this->generateCover($article, $libraryId, $coverHash, $references);

        return [
            'payload' => $this->withCoverAsset($payload, $cover),
            'meta' => [
                'generated' => true,
                'cover_hash' => $coverHash,
                'image_id' => (int) $cover->id,
                'source_library_id' => $libraryId,
                'reference_image_ids' => array_map(static fn (ImageModel $image): int => (int) $image->id, $references),
                'provider' => (string) config('geoflow.toutiao_cover.provider', 'gemini'),
                'model' => (string) config('geoflow.toutiao_cover.model', ''),
            ],
        ];
    }

    /**
     * @return list<ImageModel>
     */
    private function referenceImages(Article $article, int $libraryId): array
    {
        $limit = min(6, max(1, (int) config('geoflow.toutiao_cover.reference_limit', 4)));
        $title = mb_strtolower(trim((string) $article->title), 'UTF-8');
        $articleText = mb_strtolower(implode(' ', [
            $title,
            (string) ($article->excerpt ?? ''),
            (string) ($article->keywords ?? ''),
            mb_substr(strip_tags((string) $article->content), 0, 5000),
        ]), 'UTF-8');

        return ImageModel::query()
            ->where('library_id', $libraryId)
            ->where(function ($query): void {
                $query->whereNull('tags')->orWhere('tags', 'not like', 'ai_generated_cover;%');
            })
            ->orderBy('used_count')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'library_id', 'file_path', 'original_name', 'file_name', 'mime_type', 'tags', 'used_count'])
            ->map(function (ImageModel $image) use ($articleText, $title): array {
                $score = 0;
                $source = implode(' ', [
                    (string) ($image->tags ?? ''),
                    pathinfo((string) ($image->original_name ?? $image->file_name ?? ''), PATHINFO_FILENAME),
                ]);
                $terms = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($source, 'UTF-8')) ?: [];
                foreach (array_unique($terms) as $term) {
                    $term = trim((string) $term);
                    if (mb_strlen($term, 'UTF-8') < 2) {
                        continue;
                    }
                    if (str_contains($title, $term)) {
                        $score += 20 + mb_strlen($term, 'UTF-8');
                    } elseif (str_contains($articleText, $term)) {
                        $score += 5 + mb_strlen($term, 'UTF-8');
                    }
                }

                return ['image' => $image, 'score' => $score];
            })
            ->sortBy([
                ['score', 'desc'],
                [static fn (array $row): int => (int) $row['image']->id, 'asc'],
            ])
            ->pluck('image')
            ->filter(function (ImageModel $image): bool {
                try {
                    $this->managedImages->absolutePathForExisting((string) $image->file_path);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<ImageModel>  $references
     */
    private function coverHash(Article $article, array $references): string
    {
        return hash('sha256', json_encode([
            'version' => 1,
            'article' => [
                'title' => (string) $article->title,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'keywords' => (string) ($article->keywords ?? ''),
                'content' => (string) $article->content,
            ],
            'references' => array_map(static fn (ImageModel $image): array => [
                'id' => (int) $image->id,
                'path' => (string) $image->file_path,
            ], $references),
            'provider' => (string) config('geoflow.toutiao_cover.provider', 'gemini'),
            'model' => (string) config('geoflow.toutiao_cover.model', ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function cachedCover(Article $article, int $libraryId, string $coverHash): ?ImageModel
    {
        $cover = ImageModel::query()
            ->where('library_id', $libraryId)
            ->where('tags', 'like', '%cover_hash:'.$coverHash.'%')
            ->first();
        if (! $cover instanceof ImageModel) {
            return null;
        }

        try {
            $this->managedImages->absolutePathForExisting((string) $cover->file_path);
        } catch (Throwable) {
            return null;
        }

        $this->attachCover($article, $cover);

        return $cover;
    }

    /**
     * @param  list<ImageModel>  $references
     */
    private function generateCover(Article $article, int $libraryId, string $coverHash, array $references): ImageModel
    {
        $provider = trim((string) config('geoflow.toutiao_cover.provider', 'gemini')) ?: 'gemini';
        $model = trim((string) config('geoflow.toutiao_cover.model', ''));
        if (! Image::isFaked() && blank(config('ai.providers.'.$provider.'.key'))) {
            throw new RuntimeException('头条封面生成未配置图片模型密钥，请配置 '.$provider.' 图片提供商。');
        }

        $attachments = array_map(
            fn (ImageModel $image): LocalImage => new LocalImage(
                $this->managedImages->absolutePathForExisting((string) $image->file_path),
                filled($image->mime_type) ? (string) $image->mime_type : null,
            ),
            $references,
        );
        $prompt = $this->prompt($article);

        try {
            $pending = Image::of($prompt)
                ->attachments($attachments)
                ->landscape()
                ->quality((string) config('geoflow.toutiao_cover.quality', 'medium'))
                ->timeout(max(30, (int) config('geoflow.toutiao_cover.timeout_seconds', 120)));
            $response = $pending->generate($provider, $model !== '' ? $model : null);
            $contents = $response->firstImage()->content();
        } catch (Throwable $exception) {
            throw new RuntimeException('头条封面生成失败：'.$exception->getMessage(), 0, $exception);
        }

        $imageInfo = @getimagesizefromstring($contents);
        $extension = match ((string) ($imageInfo['mime'] ?? '')) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $stored = $this->managedImages->storeGeneratedImage(
            $contents,
            'toutiao-cover-'.$article->id.'-'.substr($coverHash, 0, 16).'.'.$extension,
        );
        try {
            $cover = DB::transaction(function () use ($article, $libraryId, $coverHash, $stored): ImageModel {
                $cover = ImageModel::query()->create($stored + [
                    'library_id' => $libraryId,
                    'tags' => 'ai_generated_cover;article:'.$article->id.';cover_hash:'.$coverHash,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $this->attachCover($article, $cover);
                $cover->library()->update(['image_count' => ImageModel::query()->where('library_id', $libraryId)->count()]);

                return $cover;
            });
        } catch (Throwable $exception) {
            $this->managedImages->cleanupUnreferenced([(string) $stored['file_path']]);

            throw $exception;
        }

        return $cover;
    }

    private function attachCover(Article $article, ImageModel $cover): void
    {
        ArticleImage::query()
            ->where('article_id', (int) $article->id)
            ->where('image_id', '!=', (int) $cover->id)
            ->whereHas('image', fn ($query) => $query->where('tags', 'like', 'ai_generated_cover;%'))
            ->delete();
        ArticleImage::query()->updateOrCreate([
            'article_id' => (int) $article->id,
            'image_id' => (int) $cover->id,
        ], ['position' => -100]);
    }

    private function prompt(Article $article): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $article->content)) ?: '');

        return implode("\n", [
            '为今日头条文章生成一张专业的 3:2 横版封面。',
            '必须综合参考图片中的真实主体、品牌色或场景特征，并围绕文章内容重新构图；不要简单拼贴，不要生成水印、平台标识、二维码或任何文字。',
            '画面要信息明确、主体完整、新闻编辑风格自然，避免夸张营销、人物面部畸变和无关装饰。',
            '文章标题：'.trim((string) $article->title),
            '文章摘要：'.trim((string) ($article->excerpt ?? '')),
            '关键词：'.trim((string) ($article->keywords ?? '')),
            '正文要点：'.mb_substr($body, 0, 1800),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withCoverAsset(array $payload, ImageModel $cover): array
    {
        $absolutePath = $this->managedImages->absolutePathForExisting((string) $cover->file_path);
        $contents = file_get_contents($absolutePath);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('生成的头条封面无法读取。');
        }

        $sourceUrl = ImageUrlNormalizer::toPublicUrl((string) $cover->file_path);
        $asset = [
            'source_url' => $sourceUrl,
            'filename' => (string) ($cover->file_name ?: $cover->filename),
            'mime_type' => (string) ($cover->mime_type ?: 'image/png'),
            'content_base64' => base64_encode($contents),
            'role' => 'cover',
        ];
        $existingAssets = is_array($payload['assets']['images'] ?? null) ? $payload['assets']['images'] : [];
        $existingAssets = array_values(array_filter($existingAssets, static fn (mixed $item): bool => ! is_array($item)
            || (string) ($item['source_url'] ?? '') !== $sourceUrl));
        $payload['assets']['images'] = array_slice([$asset, ...$existingAssets], 0, 20);
        $payload['article']['hero_image_url'] = $sourceUrl;
        $payload['article']['cover_image_url'] = $sourceUrl;

        return $payload;
    }
}
