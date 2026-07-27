<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServicePromptTest extends TestCase
{
    public function test_custom_prompt_without_variables_receives_smart_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业、可信、适合 GEO 引用的文章。',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('请写一篇专业、可信、适合 GEO 引用的文章。', $prompt);
        $this->assertStringContainsString('【任务上下文】', $prompt);
        $this->assertStringContainsString('- 文章标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('- 核心关键词：AI CRM', $prompt);
        $this->assertStringContainsString('这是来自知识库的参考资料。', $prompt);
    }

    public function test_prompt_with_variables_keeps_precise_rendering_without_extra_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '标题：{{title}}'."\n".'{{#if keyword}}关键词：{{keyword}}{{/if}}'."\n".'{{#if Knowledge}}知识：{{Knowledge}}{{/if}}',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('关键词：AI CRM', $prompt);
        $this->assertStringContainsString('知识：这是来自知识库的参考资料。', $prompt);
        $this->assertStringNotContainsString('【任务上下文】', $prompt);
    }

    public function test_english_prompt_without_variables_receives_english_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for AI search and answer engines.',
            'Reference knowledge from the business knowledge base.'
        );

        $this->assertStringContainsString('Task context:', $prompt);
        $this->assertStringContainsString('- Article title: What is AI CRM?', $prompt);
        $this->assertStringContainsString('- Core keyword: AI CRM', $prompt);
        $this->assertStringContainsString('Reference knowledge from the business knowledge base.', $prompt);
        $this->assertStringContainsString('Please output only the final article body in Markdown.', $prompt);
        $this->assertStringContainsString('Do not include citation markers such as [K1] or [K2]', $prompt);
        $this->assertStringContainsString('do not append a References, Sources, or equivalent source list', $prompt);
    }

    public function test_prompt_with_knowledge_context_forbids_public_evidence_citations(): void
    {
        $prompt = $this->renderContentPrompt(
            'GEO 诊断怎么做？',
            'GEO 诊断',
            '请写一篇基于事实证据的文章。',
            "【知识库证据】\n【证据 K1】\n来源：GEOFlow 官方文档\n内容：GEO 诊断需要先定位问题。"
        );

        $this->assertStringContainsString('【证据 K1】', $prompt);
        $this->assertStringContainsString('知识库使用要求', $prompt);
        $this->assertStringContainsString('不要在正文中暴露证据编号', $prompt);
        $this->assertStringContainsString('正文中不要插入 [K1]、[K2] 等任何引用标记', $prompt);
        $this->assertStringContainsString('不要在文末列出“参考来源”“参考资料”或其他来源清单', $prompt);
        $this->assertStringNotContainsString('并在相关句子后标注证据编号', $prompt);
    }

    public function test_generated_article_content_removes_citation_markers_and_trailing_sources(): void
    {
        $content = "# GEO 诊断\n\n事实一 [K1]，事实二［k2］，事实三【证据 K3】。\n\n普通链接：[产品文档](https://example.com/docs)。\n\n## 参考来源\n- [K1] GEOFlow 官方文档\n- https://example.com/source";

        $this->assertSame(
            "# GEO 诊断\n\n事实一，事实二，事实三。\n\n普通链接：[产品文档](https://example.com/docs)。",
            $this->sanitizeGeneratedArticleContent($content)
        );
    }

    public function test_generated_article_content_preserves_normal_source_analysis_and_links(): void
    {
        $content = "## 来源分析\n\n用户流量主要来自搜索引擎。\n\n详情见 [产品文档](https://example.com/docs)。\n\n## 结论\n\n应持续观察渠道变化。";

        $this->assertSame($content, $this->sanitizeGeneratedArticleContent($content));
    }

    public function test_generated_article_content_keeps_sections_after_a_misplaced_source_list(): void
    {
        $content = "## 正文\n\n核心内容。\n\n## 参考资料\n- 内部资料\n\n## 结论\n\n结论仍应保留。";

        $this->assertSame(
            "## 正文\n\n核心内容。\n\n## 结论\n\n结论仍应保留。",
            $this->sanitizeGeneratedArticleContent($content)
        );
    }

    public function test_generated_article_content_removes_english_source_list(): void
    {
        $content = "# Practical guide\n\nThe body remains intact [k1].\n\n**Sources:**\n1. https://example.com/source";

        $this->assertSame("# Practical guide\n\nThe body remains intact.", $this->sanitizeGeneratedArticleContent($content));
    }

    public function test_generated_article_content_removes_numbered_source_sections(): void
    {
        $chineseContent = "## 七、结论\n\n正文结论。\n\n## 八、参考资料与来源\n- 内部知识库";
        $englishContent = "## 7. Conclusion\n\nArticle conclusion.\n\n## 8. References\n- https://example.com/source";

        $this->assertSame("## 七、结论\n\n正文结论。", $this->sanitizeGeneratedArticleContent($chineseContent));
        $this->assertSame("## 7. Conclusion\n\nArticle conclusion.", $this->sanitizeGeneratedArticleContent($englishContent));
    }

    public function test_generated_article_content_preserves_fenced_code_blocks(): void
    {
        $content = "正文 [K1]\n\n```yaml\n## Sources\nkey: [K2]\n```\n\n## 结论\n\n正文结论。";
        $expected = "正文\n\n```yaml\n## Sources\nkey: [K2]\n```\n\n## 结论\n\n正文结论。";

        $this->assertSame($expected, $this->sanitizeGeneratedArticleContent($content));
    }

    public function test_generated_article_content_keeps_bold_sections_after_a_source_list(): void
    {
        $content = "**正文**\n\n核心内容。\n\n**参考来源**\n- 内部知识库\n\n**结论**\n\n结论仍应保留。";

        $this->assertSame(
            "**正文**\n\n核心内容。\n\n**结论**\n\n结论仍应保留。",
            $this->sanitizeGeneratedArticleContent($content)
        );
    }

    public function test_generated_article_content_preserves_descriptive_sources_heading(): void
    {
        $content = "## Sources: How to assess evidence\n\nA practical analysis of source quality.";

        $this->assertSame($content, $this->sanitizeGeneratedArticleContent($content));
    }

    public function test_generated_article_content_removes_citation_links_and_definitions(): void
    {
        $content = "正文[K1](https://example.com/source)，事实[^K2]。\n\n[^K2]: https://example.com/footnote";

        $this->assertSame('正文，事实。', $this->sanitizeGeneratedArticleContent($content));
    }

    public function test_generated_article_content_removes_common_source_section_titles(): void
    {
        foreach (['Citations', 'Works Cited', 'Bibliography', 'References and Further Reading', 'References ##'] as $heading) {
            $content = "## 正文\n\nArticle body.\n\n## {$heading}\n- https://example.com/source";

            $this->assertSame("## 正文\n\nArticle body.", $this->sanitizeGeneratedArticleContent($content));
        }
    }

    public function test_prompt_without_knowledge_context_still_forbids_citations_and_sources(): void
    {
        $prompt = $this->renderContentPrompt('内容运营指南', '内容运营', '请输出完整文章。', '');

        $this->assertStringContainsString('正文中不要插入 [K1]、[K2] 等任何引用标记', $prompt);
        $this->assertStringContainsString('不要在文末列出“参考来源”“参考资料”或其他来源清单', $prompt);
    }

    public function test_unknown_template_blocks_are_preserved_for_future_extensions(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}'."\n".'标题：{{title}}',
            ''
        );

        $this->assertStringContainsString('{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}', $prompt);
        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
    }

    private function renderContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $title, $keyword, $promptContent, $knowledgeContext);
    }

    private function sanitizeGeneratedArticleContent(string $content): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'sanitizeGeneratedArticleContent');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $content);
    }
}
