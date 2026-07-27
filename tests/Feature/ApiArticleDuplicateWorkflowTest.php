<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleGeoFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiArticleDuplicateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_publish_returns_stable_conflict_details_for_exact_duplicate(): void
    {
        $admin = Admin::query()->create([
            'username' => 'api-duplicate-admin',
            'password' => 'secret-123',
            'email' => 'api-duplicate-admin@example.com',
            'display_name' => 'API Duplicate Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['name' => 'API duplicate', 'slug' => 'api-duplicate']);
        $author = Author::query()->create(['name' => 'API Duplicate']);
        $content = 'API 创建文章时也必须阻止与本地历史文章完全相同的正文进入已审核和已发布状态。';
        $source = Article::query()->create([
            'title' => 'API 历史文章',
            'slug' => 'api-duplicate-source',
            'excerpt' => '历史摘要',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        try {
            app(ArticleGeoFlowService::class)->createArticle([
                'title' => 'API 今日文章',
                'content' => $content,
                'excerpt' => '今日摘要',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'risk_override_reason' => '即使填写理由，精确重复也不能放行。',
            ], (int) $admin->id);
            $this->fail('Expected exact duplicate API publish to return a conflict.');
        } catch (ApiException $exception) {
            $this->assertSame('article_duplicate_blocked', $exception->getErrorCode());
            $this->assertSame(409, $exception->getHttpStatus());
            $this->assertSame('blocked', data_get($exception->getDetails(), 'duplicate_status'));
            $this->assertSame(1.0, data_get($exception->getDetails(), 'max_similarity'));
            $this->assertSame($source->id, data_get($exception->getDetails(), 'matched_article_id'));
        }

        $created = Article::query()->where('title', 'API 今日文章')->firstOrFail();
        $this->assertSame('draft', $created->status);
        $this->assertSame('pending', $created->review_status);
    }
}
