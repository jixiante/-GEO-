<?php

namespace Tests\Feature;

use App\Exceptions\ArticleDuplicateGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleDuplicateDetector;
use App\Services\GeoFlow\ArticleDuplicateGate;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatting_punctuation_and_title_changes_are_detected_as_exact_body_duplicates(): void
    {
        $source = $this->createArticle("## 点签\n\n这是第一段，说明内容生产流程。\n\n**这是第二段**，说明发布审核。", '原始标题');
        $target = $this->createArticle("<h2>点签</h2>\n<p>这是第一段；说明内容生产流程！</p>\n<p><strong>这是第二段</strong>，说明发布审核。</p>", '完全不同的标题');

        $scan = app(ArticleDuplicateDetector::class)->record($target, 'test_exact');

        $this->assertSame('blocked', $scan->status);
        $this->assertSame(1.0, $scan->max_similarity);
        $this->assertSame($source->id, $scan->matched_article_id);
        $this->assertTrue((bool) data_get($scan->matches, '0.exact'));
    }

    public function test_chinese_near_duplicate_is_warned_but_materially_different_article_is_clean(): void
    {
        $sourceContent = implode("\n\n", [
            '企业建设内容中台时，需要先统一选题标准、资料来源和审核责任。',
            '第一阶段梳理客户问题，第二阶段沉淀事实证据，第三阶段安排多平台发布。',
            '每次发布后记录渠道、时间、链接和阅读反馈，下一轮再根据数据调整表达。',
            '这套流程的重点不是批量堆稿，而是让每篇文章都有不同的问题切口和证据。',
        ]);
        $this->createArticle($sourceContent, '内容中台流程');

        $nearDuplicate = str_replace('下一轮再根据数据调整表达', '后续再依据真实数据优化表达', $sourceContent);
        $nearResult = app(ArticleDuplicateDetector::class)->scan($nearDuplicate);
        $cleanResult = app(ArticleDuplicateDetector::class)->scan(
            '合同点签系统主要解决身份核验、签署意愿确认和存证追溯问题。实施时应先确定签署主体，再配置印章权限与审批规则。上线验收关注审计日志、证书有效性以及异常操作告警。'
        );

        $this->assertContains($nearResult['status'], ['warning', 'blocked']);
        $this->assertGreaterThanOrEqual(0.85, $nearResult['max_similarity']);
        $this->assertSame('clean', $cleanResult['status']);
        $this->assertLessThan(0.85, $cleanResult['max_similarity']);
    }

    public function test_soft_deleted_article_and_current_article_are_excluded(): void
    {
        $source = $this->createArticle('这是一篇只用于验证软删除排除逻辑的完整文章正文。', '待删除文章');
        $source->delete();

        $result = app(ArticleDuplicateDetector::class)->scan('这是一篇只用于验证软删除排除逻辑的完整文章正文。');
        $current = $this->createArticle('当前文章复检时不应该和自己进行相似度比较。', '当前文章');
        $currentScan = app(ArticleDuplicateDetector::class)->record($current, 'test_self');

        $this->assertSame('clean', $result['status']);
        $this->assertSame('clean', $currentScan->status);
        $this->assertNull($currentScan->matched_article_id);
    }

    public function test_new_candidate_article_invalidates_an_older_clean_scan(): void
    {
        $content = '文章自身没有变化，但对比文章集合变化后，旧的查重结论必须自动失效并重新检测。';
        $target = $this->createArticle($content, '先检测的文章');
        $detector = app(ArticleDuplicateDetector::class);
        $cleanScan = $detector->record($target, 'initial_scan');
        $this->assertTrue($detector->isFresh($target, $cleanScan));

        $source = $this->createArticle($content, '后来新增的重复文章');

        $this->assertFalse($detector->isFresh($target, $cleanScan));
        try {
            app(ArticleDuplicateGate::class)->check($target, 'distribution_send');
            $this->fail('Expected the newly added duplicate article to invalidate the clean scan.');
        } catch (ArticleDuplicateGateException $exception) {
            $this->assertSame('blocked', $exception->duplicateStatus);
            $this->assertSame($source->id, $exception->scan->matched_article_id);
        }
    }

    public function test_warning_can_be_overridden_with_an_audited_admin_reason(): void
    {
        config([
            'geoflow.duplicate_detection.warning_threshold' => 0.20,
            'geoflow.duplicate_detection.block_threshold' => 1.0,
        ]);
        $this->createArticle('点签 每日生成文章时会先检索知识资料，再完成内容审核与渠道分发。', '参考文章');
        $target = $this->createArticle('点签 每日生成内容时先检索企业资料，然后进入人工审核并安排渠道发布。', '待审核文章');
        $admin = Admin::query()->create([
            'username' => 'duplicate-reviewer',
            'password' => 'secret',
            'email' => 'duplicate-reviewer@example.com',
            'display_name' => 'Duplicate Reviewer',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $scan = app(ArticleDuplicateGate::class)->check(
            $target,
            'admin_review',
            (int) $admin->id,
            '两篇文章面向不同业务环节，事实与案例已经人工核对。',
        );

        $this->assertSame('warning', $scan->status);
        $this->assertTrue($scan->is_overridden);
        $this->assertSame($admin->id, $scan->overridden_by_admin_id);
        $this->assertSame('两篇文章面向不同业务环节,事实与案例已经人工核对。', $scan->override_reason);
        $this->assertNotNull($scan->overridden_at);
    }

    public function test_exact_duplicate_is_blocked_during_approval_and_falls_back_to_pending_draft(): void
    {
        $content = '每天发布前必须检查正文是否与历史草稿完全相同，避免重复内容进入任何分发渠道。';
        $this->createArticle($content, '历史文章');
        $target = $this->createArticle($content, '今日文章', [
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        try {
            app(ArticleWorkflowTransitionService::class)->transition(
                $target,
                ArticleWorkflow::normalizeState('published', 'approved'),
                'test_approval',
                null,
                null,
                true,
                ArticleWorkflow::normalizeState('draft', 'pending'),
            );
            $this->fail('Expected an exact duplicate to be blocked.');
        } catch (ArticleDuplicateGateException $exception) {
            $this->assertSame('blocked', $exception->duplicateStatus);
            $this->assertSame(1.0, $exception->scan->max_similarity);
        }

        $target->refresh();
        $this->assertSame('draft', $target->status);
        $this->assertSame('pending', $target->review_status);
        $this->assertNull($target->published_at);
        $this->assertSame('test_approval', $target->latestDuplicateScan?->trigger);
    }

    /** @param array<string, mixed> $attributes */
    private function createArticle(string $content, string $title, array $attributes = []): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'duplicate-tests'],
            ['name' => 'Duplicate tests'],
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'duplicate-tests@example.com'],
            ['name' => 'Duplicate Tests'],
        );

        return Article::query()->create(array_merge([
            'title' => $title,
            'slug' => 'duplicate-test-'.uniqid(),
            'excerpt' => '测试摘要',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ], $attributes));
    }
}
