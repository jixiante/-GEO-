<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Image as ImageModel;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Services\GeoFlow\ManagedImageFileService;
use App\Services\GeoFlow\ToutiaoCoverGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Tests\TestCase;

class ToutiaoCoverGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_reuses_a_toutiao_cover_from_task_library_materials(): void
    {
        Storage::fake('public');
        config()->set('geoflow.toutiao_cover.enabled', true);
        config()->set('geoflow.toutiao_cover.provider', 'gemini');
        config()->set('geoflow.toutiao_cover.model', 'gemini-test-image');

        $library = ImageLibrary::query()->create([
            'name' => '智能制造图库',
            'description' => '工厂与设备素材',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $stored = app(ManagedImageFileService::class)->storeUploadedImage(
            UploadedFile::fake()->image('智能工厂.jpg', 1200, 800),
        );
        $reference = ImageModel::query()->create($stored + [
            'library_id' => (int) $library->id,
            'tags' => '智能工厂 制造 设备',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $task = Task::query()->create([
            'name' => '制造业内容任务',
            'image_library_id' => (int) $library->id,
            'image_count' => 1,
            'status' => 'active',
        ]);
        $category = Category::query()->create(['name' => '产业', 'slug' => 'industry']);
        $author = Author::query()->create(['name' => '点签']);
        $article = Article::query()->create([
            'title' => '智能工厂如何提升设备运维效率',
            'slug' => 'smart-factory-maintenance',
            'excerpt' => '从预测性维护和生产数据入手，降低设备停机时间。',
            'content' => '文章分析智能制造场景中的设备巡检、故障预测和生产协同。',
            'keywords' => '智能工厂,设备运维,预测性维护',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '头条模拟渠道',
            'domain' => 'www.toutiao.com',
            'endpoint_url' => 'https://example.com',
            'channel_type' => DistributionChannel::CHANNEL_TYPE_BROWSER_RUNNER,
            'channel_config' => [
                'browser_platform' => 'toutiao',
                'browser_account_id' => 'default',
                'browser_publish_mode' => 'simulate',
            ],
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'cover-generation-test',
        ])->load(['article.task', 'channel']);
        $generatedBytes = file_get_contents(app(ManagedImageFileService::class)->absolutePathForExisting((string) $reference->file_path));
        $this->assertIsString($generatedBytes);
        Image::fake([base64_encode($generatedBytes)])->preventStrayImages();

        $service = app(ToutiaoCoverGenerationService::class);
        $first = $service->prepare($distribution, [
            'article' => ['title' => $article->title, 'content' => $article->content],
            'assets' => ['images' => []],
        ]);
        $second = $service->prepare($distribution, [
            'article' => ['title' => $article->title, 'content' => $article->content],
            'assets' => ['images' => []],
        ]);

        $this->assertTrue($first['meta']['generated']);
        $this->assertSame([(int) $reference->id], $first['meta']['reference_image_ids']);
        $this->assertSame($first['meta']['cover_hash'], $second['meta']['cover_hash']);
        $this->assertSame('cover', $first['payload']['assets']['images'][0]['role']);
        $this->assertNotSame('', $first['payload']['assets']['images'][0]['content_base64']);
        $this->assertSame($first['payload']['article']['cover_image_url'], $first['payload']['article']['hero_image_url']);
        $this->assertDatabaseHas('article_images', [
            'article_id' => (int) $article->id,
            'image_id' => (int) $first['meta']['image_id'],
            'position' => -100,
        ]);
        Image::assertGenerated(fn ($prompt): bool => $prompt->isLandscape()
            && $prompt->contains('智能工厂如何提升设备运维效率')
            && $prompt->attachments->count() === 1);
    }
}
