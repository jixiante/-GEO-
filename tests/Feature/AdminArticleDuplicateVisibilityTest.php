<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminArticleDuplicateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_shows_duplicate_status_similarity_and_matched_article(): void
    {
        $admin = Admin::query()->create([
            'username' => 'duplicate-ui-admin',
            'password' => 'secret-123',
            'email' => 'duplicate-ui-admin@example.com',
            'display_name' => 'Duplicate UI Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['name' => '查重展示', 'slug' => 'duplicate-visibility']);
        $author = Author::query()->create(['name' => '查重展示作者']);
        $content = '编辑页需要清楚展示重复文章、最高相似度和处理阈值，便于审核人员判断是否可以发布。';
        $source = $this->createArticle($category, $author, '已存在的参考文章', $content);
        $target = $this->createArticle($category, $author, '准备审核的新文章', $content);
        app(ArticleDuplicateDetector::class)->record($target, 'admin_save', (int) $admin->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $target->id]))
            ->assertOk()
            ->assertSee('正文查重详情')
            ->assertSee('重复拦截')
            ->assertSee('最高相似度 100.0%')
            ->assertSee('#'.$source->id.' '.$source->title)
            ->assertSee('规范化后的可见正文完全相同')
            ->assertSee('风险或近似重复放行理由');
    }

    private function createArticle(Category $category, Author $author, string $title, string $content): Article
    {
        return Article::query()->create([
            'title' => $title,
            'slug' => 'duplicate-visibility-'.uniqid(),
            'excerpt' => '查重展示摘要',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }
}
