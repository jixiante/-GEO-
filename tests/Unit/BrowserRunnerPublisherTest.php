<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\BrowserRunnerClient;
use App\Services\GeoFlow\BrowserRunnerPublisher;
use App\Services\GeoFlow\DistributionHttpException;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrowserRunnerPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_through_the_local_browser_runner(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'toutiao-123',
                'remote_url' => 'https://www.toutiao.com/article/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('toutiao-123', $result['remote_id']);
        $this->assertSame('local_playwright', $result['remote_meta']['transport']);
        $this->assertSame('toutiao', $result['remote_meta']['platform']);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://example.com/v1/publish'
                && $request->hasHeader('Authorization', 'Bearer runner-pairing-token-123456')
                && $request['platform'] === 'toutiao'
                && $request['account_id'] === 'company_main'
                && $request['idempotency_key'] === 'browser-test-key'
                && ! isset($request['contract']);
        });
    }

    public function test_toutiao_publish_uses_the_exact_approved_platform_title(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'toutiao-456',
                'remote_url' => 'https://www.toutiao.com/article/456',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();
        $canonicalTitle = '电子合同OpenAPI回调丢了怎么办？幂等、重试和状态对账怎么设计';
        $approvedTitle = '电子合同OpenAPI回调丢失：幂等、重试与状态对账';
        $payload = [
            'article' => ['title' => $canonicalTitle, 'content' => '正文'],
            'assets' => ['images' => []],
        ];
        $payloadHash = $this->payloadHash($payload);
        $article = $distribution->article()->firstOrFail();
        $article->forceFill(['title' => $canonicalTitle])->save();
        $distribution->forceFill([
            'payload_hash' => $payloadHash,
            'remote_meta' => [
                'platform_title_approval' => [
                    'approved' => true,
                    'platform' => 'toutiao',
                    'article_distribution_id' => (int) $distribution->id,
                    'approved_title' => $approvedTitle,
                    'approved_by' => 'admin:42',
                    'approved_at' => '2026-08-04T18:00:00+08:00',
                    'payload_hash' => $payloadHash,
                ],
            ],
        ])->save();

        app(BrowserRunnerPublisher::class)->publish(
            $distribution->fresh('channel.activeSecret'),
            $payload,
        );

        Http::assertSent(fn ($request): bool => $request['payload']['article']['title'] === $approvedTitle);
        $this->assertSame($canonicalTitle, $article->fresh()->title);
    }

    public function test_toutiao_platform_title_approval_rejects_a_changed_canonical_payload(): void
    {
        Http::fake();
        [, $distribution] = $this->makeDistribution();
        $approvedPayload = [
            'article' => [
                'title' => '电子合同OpenAPI回调丢了怎么办？幂等、重试和状态对账怎么设计',
                'content' => '正文',
            ],
            'assets' => ['images' => []],
        ];
        $payloadHash = $this->payloadHash($approvedPayload);
        $distribution->forceFill([
            'payload_hash' => $payloadHash,
            'remote_meta' => [
                'platform_title_approval' => [
                    'approved' => true,
                    'platform' => 'toutiao',
                    'article_distribution_id' => (int) $distribution->id,
                    'approved_title' => '电子合同OpenAPI回调丢失：幂等、重试与状态对账',
                    'approved_by' => 'admin:42',
                    'approved_at' => '2026-08-04T18:00:00+08:00',
                    'payload_hash' => $payloadHash,
                ],
            ],
        ])->save();
        $changedPayload = $approvedPayload;
        $changedPayload['article']['content'] = '批准后被修改的正文';

        try {
            app(BrowserRunnerPublisher::class)->publish(
                $distribution->fresh('channel.activeSecret'),
                $changedPayload,
            );
            $this->fail('A changed canonical payload must invalidate the approved platform title.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('经批准的平台标题与当前头条分发任务不匹配', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_baijiahao_accepts_explicit_publish_success_before_a_public_url_is_available(): void
    {
        $sourceUrl = 'https://www.court.gov.cn/zixun/xiangqing/233181.html';
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'current_url' => 'https://baijiahao.baidu.com/builder/rc/clue?from=news',
                    'evidence_source' => 'explicit_success_text',
                    'evidence_text' => '发布成功',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                        'links' => [
                            'required' => true,
                            'ok' => true,
                            'expectedCount' => 1,
                            'actualCount' => 1,
                            'matchedCount' => 1,
                            'missingCount' => 0,
                            'expectedUrls' => [$sourceUrl],
                            'actualUrls' => [$sourceUrl],
                            'missingUrls' => [],
                        ],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                    'ai_disclosure_verification' => [
                        'required' => true,
                        'platform' => 'baijiahao',
                        'selected' => true,
                        'option_text' => '采用AI生成内容',
                        'evidence' => [
                            'attribute' => 'checked',
                            'value' => true,
                        ],
                    ],
                    'required_unchecked_options_verification' => $this->baijiahaoRequiredUncheckedOptionsVerification(),
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => [
                'title' => '浏览器发布测试',
                'content' => '正文',
                'content_html' => '<p>正文</p><p><a href="'.$sourceUrl.'">最高人民法院</a></p>',
                'is_ai_generated' => true,
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('published', $result['status']);
        $this->assertNull($result['remote_id']);
        $this->assertNull($result['remote_url']);
        $this->assertSame('发布成功', $result['remote_meta']['evidence_text']);
        $this->assertSame('baijiahao', $result['remote_meta']['platform']);
        $this->assertSame('published', $result['remote_meta']['runner_status']);
        $this->assertTrue($result['remote_meta']['required_unchecked_options_verification']['all_unchecked']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://example.com/v2/publish'
            && $request['platform'] === 'baijiahao'
            && $request['contract'] === 2
            && $request['verification_contract_version'] === 2);
    }

    public function test_baijiahao_v2_not_found_does_not_fall_back_to_the_legacy_publish_route(): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => false,
                'code' => 'not_found',
                'message' => 'Interface not found.',
            ], 404),
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        try {
            app(BrowserRunnerClient::class)->publish($distribution, [
                'article' => ['title' => 'Baijiahao v2 contract test', 'content' => 'Body'],
                'assets' => ['images' => []],
            ]);
            $this->fail('A legacy Runner must fail closed instead of receiving a v1 publish request.');
        } catch (DistributionHttpException $exception) {
            $this->assertSame(404, $exception->status());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/v2/publish'
            && $request['contract'] === 2);
    }

    public function test_baijiahao_rejects_success_without_verified_derivative_options_off(): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_success_text',
                    'evidence_text' => '发布成功',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('浏览器发布助手未返回可信的发布确认或内容完整性校验');

        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '百家号安全校验测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_baijiahao_accepts_verified_native_negative_derivative_option_evidence(): void
    {
        $verification = $this->baijiahaoRequiredUncheckedOptionsVerification();
        foreach ($verification['options'] as &$option) {
            $option['evidence'] = ['attribute' => 'checked', 'value' => false];
        }
        unset($option);
        $this->fakeSuccessfulBaijiahaoPublishWithRequiredOptions($verification);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '百家号原生开关证据测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertTrue($result['remote_meta']['required_unchecked_options_verification']['all_unchecked']);
    }

    public function test_baijiahao_rejects_a_string_false_as_native_unchecked_evidence(): void
    {
        $verification = $this->baijiahaoRequiredUncheckedOptionsVerification();
        $verification['options'][0]['evidence'] = ['attribute' => 'checked', 'value' => 'false'];
        $this->fakeSuccessfulBaijiahaoPublishWithRequiredOptions($verification);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('浏览器发布助手未返回可信的发布确认或内容完整性校验');

        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '百家号无效原生开关证据测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_baijiahao_publish_mode_cannot_be_bypassed_by_a_draft_result(): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'draft',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_draft_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao', 'publish');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('浏览器发布助手未返回可信的发布确认或内容完整性校验');

        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '百家号发布模式绑定测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_draft_mode_accepts_a_verified_draft_result(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'draft',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_draft_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu', 'draft');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器草稿模式绑定测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('draft', $result['status']);
        Http::assertSent(fn ($request): bool => $request['publish_mode'] === 'draft');
    }

    #[DataProvider('mismatchedBrowserModeStatusProvider')]
    public function test_runner_status_must_match_the_configured_browser_publish_mode(
        string $publishMode,
        string $status,
        string $evidenceSource,
    ): void {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => $status,
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => $evidenceSource,
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu', $publishMode);

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器模式与状态不匹配测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function mismatchedBrowserModeStatusProvider(): array
    {
        return [
            'publish rejects draft' => ['publish', 'draft', 'explicit_draft_text'],
            'publish rejects simulation' => ['publish', 'simulated', 'simulation_complete'],
            'draft rejects published' => ['draft', 'published', 'public_url_pattern'],
            'draft rejects reviewing' => ['draft', 'reviewing', 'explicit_reviewing_text'],
            'draft rejects simulation' => ['draft', 'simulated', 'simulation_complete'],
            'simulation rejects published' => ['simulate', 'published', 'public_url_pattern'],
            'simulation rejects reviewing' => ['simulate', 'reviewing', 'explicit_reviewing_text'],
            'simulation rejects draft' => ['simulate', 'draft', 'explicit_draft_text'],
        ];
    }

    public function test_it_rejects_a_runner_success_without_trustworthy_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'current_url' => 'https://mp.163.com/#/article-publish',
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $this->expectExceptionMessage('浏览器发布助手未返回可信的发布确认或内容完整性校验');
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_ai_generated_publish_requires_verified_platform_disclosure(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'zhihu-123',
                'remote_url' => 'https://zhuanlan.zhihu.com/p/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'AI generated browser publish test',
                'content' => 'Body',
                'is_ai_generated' => true,
            ],
            'assets' => ['images' => []],
        ]);
    }

    public function test_ai_generated_publish_rejects_disclosure_for_another_platform(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'toutiao',
            'selected' => true,
            'option_text' => '包含 AI 辅助创作',
            'evidence' => [
                'attribute' => 'aria-selected',
                'value' => 'true',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_unapproved_disclosure_option_text(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'zhihu',
            'selected' => true,
            'option_text' => '采用AI生成内容',
            'evidence' => [
                'attribute' => 'aria-selected',
                'value' => 'true',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_unknown_disclosure_evidence_attributes(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'zhihu',
            'selected' => true,
            'option_text' => '包含 AI 辅助创作',
            'evidence' => [
                'attribute' => 'class',
                'value' => 'selected',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_false_disclosure_evidence(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'zhihu',
            'selected' => true,
            'option_text' => '包含 AI 辅助创作',
            'evidence' => [
                'attribute' => 'aria-selected',
                'value' => 'false',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_active_disclosure_evidence(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'zhihu',
            'selected' => true,
            'option_text' => '包含 AI 辅助创作',
            'evidence' => [
                'attribute' => 'data-state',
                'value' => 'active',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_checked_false_string_evidence(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'zhihu',
            'selected' => true,
            'option_text' => '包含 AI 辅助创作',
            'evidence' => [
                'attribute' => 'checked',
                'value' => 'false',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_blank_selector_state_evidence(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'sohu',
            'selected' => true,
            'option_text' => '包含AI创作内容',
            'evidence' => [
                'attribute' => 'selector_state',
                'value' => '   ',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('sohu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_rejects_wrong_selector_state_for_baijiahao(): void
    {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => 'baijiahao',
            'selected' => true,
            'option_text' => '采用AI生成内容',
            'evidence' => [
                'attribute' => 'selector_state',
                'value' => '.untrusted-selected-control',
            ],
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());
    }

    public function test_ai_generated_publish_accepts_verified_platform_disclosure(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'zhihu-123',
                'remote_url' => 'https://zhuanlan.zhihu.com/p/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'ai_disclosure_verification' => [
                        'required' => true,
                        'platform' => 'zhihu',
                        'selected' => true,
                        'option_text' => '包含 AI 辅助创作',
                        'evidence' => [
                            'attribute' => 'aria-selected',
                            'value' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'AI generated browser publish test',
                'content' => 'Body',
                'is_ai_generated' => true,
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertTrue($result['remote_meta']['ai_disclosure_verification']['selected']);
        $this->assertSame('包含 AI 辅助创作', $result['remote_meta']['ai_disclosure_verification']['option_text']);
    }

    /**
     * @param  array{attribute:string,value:mixed}  $evidence
     */
    #[DataProvider('validAiDisclosureEvidenceProvider')]
    public function test_ai_generated_publish_accepts_platform_option_and_positive_evidence(
        string $platform,
        string $optionText,
        array $evidence,
    ): void {
        $this->fakeSuccessfulAiPublish([
            'required' => true,
            'platform' => $platform,
            'selected' => true,
            'option_text' => $optionText,
            'evidence' => $evidence,
        ]);
        [, $distribution] = $this->makeDistribution($platform);

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, $this->aiGeneratedPayload());

        $this->assertTrue($result['remote_meta']['ai_disclosure_verification']['selected']);
    }

    /**
     * @return array<string,array{0:string,1:string,2:array{attribute:string,value:mixed}}>
     */
    public static function validAiDisclosureEvidenceProvider(): array
    {
        return [
            'toutiao checked' => ['toutiao', '引用AI', ['attribute' => 'checked', 'value' => true]],
            'zhihu selected' => ['zhihu', '包含 AI 辅助创作 作者对内容负责', ['attribute' => 'selected', 'value' => true]],
            'zhihu aria checked' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'aria-checked', 'value' => 'true']],
            'zhihu aria selected' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'aria-selected', 'value' => 'true']],
            'zhihu data state checked' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'data-state', 'value' => 'checked']],
            'zhihu data state selected' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'data-state', 'value' => 'selected']],
            'zhihu data state on' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'data-state', 'value' => 'on']],
            'zhihu data state true' => ['zhihu', '包含 AI 辅助创作', ['attribute' => 'data-state', 'value' => 'true']],
            'baijiahao selector state' => [
                'baijiahao',
                '采用AI生成内容',
                [
                    'attribute' => 'selector_state',
                    'value' => '.one-checkbox-wrapper:has-text("采用AI生成内容") .one-checkbox.one-checkbox-checked',
                ],
            ],
            'sohu selector state' => ['sohu', '包含AI创作内容', ['attribute' => 'selector_state', 'value' => '.ai-disclosure-selected']],
        ];
    }

    public function test_publish_with_source_links_requires_positive_link_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'zhihu-123',
                'remote_url' => 'https://zhuanlan.zhihu.com/p/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Source link evidence test',
                'content' => 'Official source',
                'content_html' => '<p><a href="https://example.test/source">Official source</a></p>',
            ],
            'assets' => ['images' => []],
        ]);
    }

    public function test_publish_with_source_links_accepts_exact_rendered_link_evidence(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'zhihu-123',
                'remote_url' => 'https://zhuanlan.zhihu.com/p/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                        'links' => [
                            'required' => true,
                            'ok' => true,
                            'expectedCount' => 1,
                            'actualCount' => 1,
                            'matchedCount' => 1,
                            'missingCount' => 0,
                            'expectedUrls' => ['https://example.test/source'],
                            'actualUrls' => ['https://example.test/source'],
                            'missingUrls' => [],
                        ],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('zhihu');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Source link evidence test',
                'content' => 'Official source',
                'content_html' => '<p><a href="https://example.test/source">Official source</a></p>',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertTrue($result['remote_meta']['content_verification']['links']['ok']);
        $this->assertSame(
            ['https://example.test/source'],
            $result['remote_meta']['content_verification']['links']['actualUrls'],
        );
    }

    public function test_sohu_publish_accepts_approved_plain_source_names_when_editor_preserves_no_links(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $approval = [
            'approved' => true,
            'platform' => 'sohu',
            'article_distribution_id' => (int) $distribution->id,
            'payload_hash' => $payloadHash,
            'approved_by' => 'admin:42',
            'approved_at' => '2026-07-29T09:00:00+08:00',
        ];
        $distribution->forceFill([
            'payload_hash' => $payloadHash,
            'remote_meta' => ['plain_source_names_approval' => $approval],
        ])->save();
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash);

        $result = app(BrowserRunnerPublisher::class)->publish(
            $distribution->fresh('channel.activeSecret'),
            $payload,
        );

        $this->assertSame(
            0,
            $result['remote_meta']['content_verification']['links']['actualCount'],
        );
        Http::assertSent(fn ($request): bool => $request['plain_source_names_approval'] === $approval);
    }

    public function test_sohu_plain_source_names_approval_rejects_a_changed_outgoing_payload(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $approvedPayload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($approvedPayload);
        $this->storePlainSourceNamesApproval($distribution, $payloadHash);
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash);
        $changedPayload = $approvedPayload;
        $changedPayload['article']['title'] = 'Changed after approval';

        $this->assertBrowserPublishRejected($distribution, $changedPayload);
        Http::assertSent(
            fn ($request): bool => ! isset($request['plain_source_names_approval']),
        );
    }

    public function test_sohu_plain_source_names_fallback_rejects_missing_per_distribution_approval(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $distribution->forceFill(['payload_hash' => $payloadHash])->save();
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash);

        $this->assertBrowserPublishRejected($distribution, $payload);
        Http::assertSent(
            fn ($request): bool => ! isset($request['plain_source_names_approval']),
        );
    }

    public function test_plain_source_names_fallback_rejects_a_non_sohu_distribution(): void
    {
        [, $distribution] = $this->makeDistribution('zhihu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $this->storePlainSourceNamesApproval($distribution, $payloadHash);
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash);

        $this->assertBrowserPublishRejected($distribution, $payload);
        Http::assertSent(
            fn ($request): bool => ! isset($request['plain_source_names_approval']),
        );
    }

    public function test_sohu_plain_source_names_fallback_rejects_missing_human_approval_evidence(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $this->storePlainSourceNamesApproval($distribution, $payloadHash, [
            'approved_by' => '   ',
        ]);
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash);

        $this->assertBrowserPublishRejected($distribution, $payload);
        Http::assertSent(
            fn ($request): bool => ! isset($request['plain_source_names_approval']),
        );
    }

    public function test_sohu_plain_source_names_fallback_rejects_a_runner_payload_hash_mismatch(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $approval = $this->storePlainSourceNamesApproval($distribution, $payloadHash);
        $differentHash = $payloadHash === str_repeat('a', 64)
            ? str_repeat('b', 64)
            : str_repeat('a', 64);
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash, [
            'payload_hash' => $differentHash,
        ]);

        $this->assertBrowserPublishRejected($distribution, $payload);
        Http::assertSent(
            fn ($request): bool => $request['plain_source_names_approval'] === $approval,
        );
    }

    public function test_sohu_plain_source_names_fallback_rejects_mismatched_runner_source_names(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $this->storePlainSourceNamesApproval($distribution, $payloadHash);
        $this->fakeSuccessfulPlainSourceNamesPublish($distribution, $payloadHash, [
            'actualNames' => ['Different source'],
        ]);

        $this->assertBrowserPublishRejected($distribution, $payload);
    }

    public function test_sohu_plain_source_names_fallback_requires_exactly_zero_actual_links(): void
    {
        [, $distribution] = $this->makeDistribution('sohu');
        $payload = $this->sourceLinkPayload();
        $payloadHash = $this->payloadHash($payload);
        $this->storePlainSourceNamesApproval($distribution, $payloadHash);
        $this->fakeSuccessfulPlainSourceNamesPublish(
            $distribution,
            $payloadHash,
            linkOverrides: [
                'actualCount' => 1,
                'matchedCount' => 1,
                'missingCount' => 0,
                'actualUrls' => ['https://example.test/source'],
                'missingUrls' => [],
            ],
        );

        $this->assertBrowserPublishRejected($distribution, $payload);
    }

    public function test_distribution_payload_marks_ai_generated_articles_for_disclosure(): void
    {
        [, $distribution] = $this->makeDistribution('zhihu');
        $article = $distribution->article()->firstOrFail();
        $article->update(['is_ai_generated' => 1]);

        $payload = app(DistributionPayloadBuilder::class)->build($article->fresh());

        $this->assertTrue($payload['article']['is_ai_generated']);
    }

    public function test_baijiahao_publish_requires_verified_cover_evidence(): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_reviewing_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $this->expectException(\RuntimeException::class);
        app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Browser publish test', 'content' => 'Body'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_baijiahao_publish_accepts_verified_cover_evidence(): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'reviewing',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_reviewing_text',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                    'required_unchecked_options_verification' => $this->baijiahaoRequiredUncheckedOptionsVerification(),
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('baijiahao');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => 'Browser publish test', 'content' => 'Body'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('baijiahao', $result['remote_meta']['platform']);
        $this->assertTrue($result['remote_meta']['cover_verification']['dialog_closed']);
    }

    public function test_it_accepts_a_platform_success_url_without_inventing_a_remote_id(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'platform_success_url',
                    'evidence_text' => null,
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertNull($result['remote_id']);
        $this->assertNull($result['remote_url']);
        $this->assertSame('platform_success_url', $result['remote_meta']['evidence_source']);
    }

    public function test_it_accepts_a_verified_simulation_without_marking_it_published(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'simulated',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'simulation_complete',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('toutiao', 'simulate');

        $result = app(BrowserRunnerPublisher::class)->publish($distribution, [
            'article' => ['title' => '浏览器模拟发布测试', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('simulated', $result['status']);
        $this->assertNull($result['remote_id']);
        $this->assertSame('simulation_complete', $result['remote_meta']['evidence_source']);
        Http::assertSent(fn ($request): bool => $request['publish_mode'] === 'simulate'
            && $request['idempotency_key'] === 'browser-test-key:simulate');
    }

    public function test_orchestrator_persists_simulation_without_a_remote_publication(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'simulated',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'simulation_complete',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                ],
            ]),
        ]);
        [, $distribution] = $this->makeDistribution('toutiao', 'simulate');

        app(DistributionOrchestrator::class)->process($distribution);

        $distribution->refresh();
        $this->assertSame('simulated', $distribution->status);
        $this->assertNull($distribution->remote_id);
        $this->assertNull($distribution->remote_url);
        $this->assertSame('simulation_complete', $distribution->remote_meta['evidence_source'] ?? null);
    }

    public function test_it_surfaces_manual_action_responses_without_retrying(): void
    {
        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => false,
                'code' => 'manual_action_required',
                'message' => '账号登录已失效',
            ], 409),
        ]);
        [, $distribution] = $this->makeDistribution();

        $this->expectExceptionMessage('浏览器发布需要人工处理：账号登录已失效');
        app(BrowserRunnerClient::class)->publish($distribution, ['article' => ['title' => '测试']]);
    }

    public function test_health_request_includes_platform_account_and_token(): void
    {
        Http::fake([
            'https://example.com/v1/health*' => Http::response(['ok' => true, 'enabled' => true]),
        ]);
        [$channel] = $this->makeDistribution();

        $result = app(BrowserRunnerPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('browser_runner', $result['channel_type']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/health')
            && str_contains($request->url(), 'platform=toutiao')
            && str_contains($request->url(), 'account_id=company_main'));
    }

    /**
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(string $platform = 'toutiao', string $publishMode = 'publish'): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => '公司头条浏览器',
            'domain' => 'www.toutiao.com',
            'endpoint_url' => 'https://example.com',
            'channel_type' => 'browser_runner',
            'channel_config' => [
                'browser_platform' => $platform,
                'browser_account_id' => 'company_main',
                'browser_publish_mode' => $publishMode,
                'browser_timeout_seconds' => 180,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'browser_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('runner-pairing-token-123456'),
            'status' => 'active',
            'scopes' => ['browser.publish'],
        ]);
        $category = Category::query()->create(['name' => '科技', 'slug' => 'technology']);
        $author = Author::query()->create(['name' => '点签']);
        $article = Article::query()->create([
            'title' => '浏览器发布测试',
            'slug' => 'browser-publish-test',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'browser-test-key',
        ]);

        return [$channel->load('activeSecret'), $distribution->load('channel.activeSecret')];
    }

    /**
     * @param  array<string,mixed>  $verification
     */
    private function fakeSuccessfulBaijiahaoPublishWithRequiredOptions(array $verification): void
    {
        Http::fake([
            'https://example.com/v2/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => null,
                'remote_url' => null,
                'remote_meta' => [
                    'evidence_source' => 'explicit_success_text',
                    'evidence_text' => '发布成功',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                    'required_unchecked_options_verification' => $verification,
                ],
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $verification
     */
    private function fakeSuccessfulAiPublish(array $verification): void
    {
        $endpoint = ($verification['platform'] ?? null) === 'baijiahao'
            ? 'https://example.com/v2/publish'
            : 'https://example.com/v1/publish';
        Http::fake([
            $endpoint => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'zhihu-123',
                'remote_url' => 'https://zhuanlan.zhihu.com/p/123',
                'remote_meta' => array_replace([
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                    ],
                    'cover_verification' => [
                        'required' => true,
                        'upload_accepted' => true,
                        'dialog_closed' => true,
                    ],
                    'ai_disclosure_verification' => $verification,
                ], ($verification['platform'] ?? null) === 'baijiahao'
                    ? ['required_unchecked_options_verification' => $this->baijiahaoRequiredUncheckedOptionsVerification()]
                    : []),
            ]),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function baijiahaoRequiredUncheckedOptionsVerification(): array
    {
        return [
            'required' => true,
            'platform' => 'baijiahao',
            'all_unchecked' => true,
            'options' => [
                [
                    'text' => '自动生成视频',
                    'unchecked' => true,
                    'changed' => true,
                    'evidence' => [
                        'attribute' => 'selector_state',
                        'value' => '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox:not(.one-checkbox-checked)',
                    ],
                ],
                [
                    'text' => '自动生成播客',
                    'unchecked' => true,
                    'changed' => false,
                    'evidence' => [
                        'attribute' => 'selector_state',
                        'value' => '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox:not(.one-checkbox-checked)',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiGeneratedPayload(): array
    {
        return [
            'article' => [
                'title' => 'AI generated browser publish test',
                'content' => 'Body',
                'is_ai_generated' => true,
            ],
            'assets' => ['images' => []],
        ];
    }

    /**
     * @param  array<string,mixed>  $sourceNameOverrides
     * @param  array<string,mixed>  $linkOverrides
     */
    private function fakeSuccessfulPlainSourceNamesPublish(
        ArticleDistribution $distribution,
        string $payloadHash,
        array $sourceNameOverrides = [],
        array $linkOverrides = [],
    ): void {
        $sourceNames = array_replace([
            'ok' => true,
            'platform' => 'sohu',
            'article_distribution_id' => (int) $distribution->id,
            'payload_hash' => $payloadHash,
            'expectedCount' => 1,
            'actualCount' => 1,
            'matchedCount' => 1,
            'missingCount' => 0,
            'expectedNames' => ['Official source'],
            'actualNames' => ['Official source'],
            'missingNames' => [],
        ], $sourceNameOverrides);
        $links = array_replace([
            'required' => true,
            'ok' => false,
            'expectedCount' => 1,
            'actualCount' => 0,
            'matchedCount' => 0,
            'missingCount' => 1,
            'expectedUrls' => ['https://example.test/source'],
            'actualUrls' => [],
            'missingUrls' => ['https://example.test/source'],
            'plain_source_names' => $sourceNames,
        ], $linkOverrides);

        Http::fake([
            'https://example.com/v1/publish' => Http::response([
                'ok' => true,
                'status' => 'published',
                'remote_id' => 'sohu-123',
                'remote_url' => 'https://www.sohu.com/a/123',
                'remote_meta' => [
                    'evidence_source' => 'public_url_pattern',
                    'content_verification' => [
                        'title' => ['ok' => true],
                        'body' => ['ok' => true],
                        'links' => $links,
                    ],
                ],
            ]),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLinkPayload(): array
    {
        return [
            'article' => [
                'title' => 'Source name fallback test',
                'content' => 'Official source',
                'content_html' => '<p><a href="https://example.test/source">Official source</a></p>',
            ],
            'assets' => ['images' => []],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function storePlainSourceNamesApproval(
        ArticleDistribution $distribution,
        string $payloadHash,
        array $overrides = [],
    ): array {
        $approval = array_replace([
            'approved' => true,
            'platform' => 'sohu',
            'article_distribution_id' => (int) $distribution->id,
            'payload_hash' => $payloadHash,
            'approved_by' => 'admin:42',
            'approved_at' => '2026-07-29T09:00:00+08:00',
        ], $overrides);
        $distribution->forceFill([
            'payload_hash' => $payloadHash,
            'remote_meta' => ['plain_source_names_approval' => $approval],
        ])->save();

        return $approval;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertBrowserPublishRejected(
        ArticleDistribution $distribution,
        array $payload,
    ): void {
        try {
            app(BrowserRunnerPublisher::class)->publish(
                $distribution->fresh('channel.activeSecret'),
                $payload,
            );
            $this->fail('Browser publish must reject invalid source-name fallback evidence.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
    }
}
