<?php

namespace Tests\Unit;

use App\Services\AiExposure\AiExposureAnswerAnalyzer;
use App\Services\AiExposure\AiExposureSourceResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AiExposureAnswerAnalyzerTest extends TestCase
{
    #[Test]
    public function it_separates_title_mentions_from_exact_article_url_citations(): void
    {
        $resolver = new AiExposureSourceResolver;
        $analyzer = new AiExposureAnswerAnalyzer($resolver);
        $sources = [[
            'key' => hash('sha256', 'https://www.zhihu.com/p/123456'),
            'label' => '知乎',
            'url' => 'https://www.zhihu.com/p/123456?utm_source=test',
            'host' => 'www.zhihu.com',
            'channel_id' => 1,
            'type' => 'distribution',
        ]];

        $mentionOnly = $analyzer->analyze('建议阅读《企业 GEO 实战指南》，来源是 https://www.zhihu.com/。', '企业 GEO 实战指南', $sources);
        $citation = $analyzer->analyze('来源：https://www.zhihu.com/p/123456#answer', '企业 GEO 实战指南', $sources);

        $this->assertTrue($mentionOnly['mentioned']);
        $this->assertFalse($mentionOnly['cited']);
        $this->assertFalse($citation['mentioned']);
        $this->assertTrue($citation['cited']);
        $this->assertSame('知乎', $citation['matched_sources'][0]['label']);
    }

    #[Test]
    public function it_does_not_treat_an_unrelated_url_on_the_same_domain_as_a_citation(): void
    {
        $resolver = new AiExposureSourceResolver;
        $analyzer = new AiExposureAnswerAnalyzer($resolver);
        $sources = [[
            'key' => hash('sha256', 'https://example.com/articles/target'),
            'label' => 'Example',
            'url' => 'https://example.com/articles/target',
            'host' => 'example.com',
            'channel_id' => 1,
            'type' => 'distribution',
        ]];

        $result = $analyzer->analyze('See https://example.com/articles/other', 'Target Article', $sources);

        $this->assertFalse($result['mentioned']);
        $this->assertFalse($result['cited']);
        $this->assertSame([], $result['matched_sources']);
    }

    #[Test]
    public function it_preserves_article_identity_query_parameters_while_ignoring_tracking_parameters(): void
    {
        $resolver = new AiExposureSourceResolver;
        $analyzer = new AiExposureAnswerAnalyzer($resolver);
        $sources = [[
            'key' => hash('sha256', 'https://baijiahao.baidu.com/s?id=123456'),
            'label' => 'Baijiahao',
            'url' => 'https://baijiahao.baidu.com/s?utm_source=share&id=123456',
            'host' => 'baijiahao.baidu.com',
            'channel_id' => 1,
            'type' => 'distribution',
        ]];

        $matching = $analyzer->analyze(
            'Source: https://baijiahao.baidu.com/s?id=123456&utm_medium=answer',
            'Target Article',
            $sources
        );
        $differentArticle = $analyzer->analyze(
            'Source: https://baijiahao.baidu.com/s?id=654321',
            'Target Article',
            $sources
        );

        $this->assertTrue($matching['cited']);
        $this->assertFalse($differentArticle['cited']);
        $this->assertSame([], $differentArticle['matched_sources']);
    }
}
