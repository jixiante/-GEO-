import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { articleFromRequest, coverFileFromRequest, htmlToPlainText, sanitizeArticleHtml, uploadFilesFromRequest } from '../src/content.js';
import { getPlatform, platformKeys } from '../src/platforms.js';
import { StateStore } from '../src/state-store.js';
import { BrowserRunner, sanitizeIdentifier } from '../src/browser-runner.js';
import {
  assessTextIntegrity,
  applyRequiredCover,
  classifyOutcomeEvidence,
  classifyOutcomeText,
  classifyPlatformSuccessUrlEvidence,
  clickConfirmationControl,
  clickPublishControl,
  editorLocationMatches,
  extractRemoteReference,
  findBlockingText,
  prepareRequiredImageUpload,
  publishWithBrowser,
  readinessControlsAreUsable,
} from '../src/automation.js';
import { isPrivateAddress } from '../src/network.js';
import { article12Payload } from './fixtures/article-12-payload.js';

test('registers all supported domestic publishing platforms', () => {
  assert.deepEqual(platformKeys(), ['toutiao', 'baijiahao', 'zhihu', 'sohu', 'netease', 'csdn', 'xiaohongshu']);
  for (const key of platformKeys()) {
    const platform = getPlatform(key);
    assert.ok(platform.publishUrl.startsWith('https://'));
    assert.ok(platform.titleSelectors.length > 0);
    assert.ok(platform.editorSelectors.length > 0);
  }
});

test('baijiahao targets the lexical title and the ueditor iframe body', () => {
  const platform = getPlatform('baijiahao');

  assert.equal(platform.titleSelectors[0], '[data-testid="news-title-input"] [contenteditable="true"]');
  assert.deepEqual(platform.editorSelectors, ['body[contenteditable="true"]']);
  assert.equal(platform.editorSelectors.includes('[contenteditable="true"]'), false);
  assert.equal(platform.requiresCover, true);
  assert.equal(platform.requiresImage, undefined);
  assert.equal(platform.maxTitleLength, 64);
  assert.equal(platform.richInsertMode, 'dom');
  assert.deepEqual(platform.coverFlow.triggerTexts, ['选择封面', '更换']);
  assert.deepEqual(platform.coverFlow.fileInputSelectors, ['input[type="file"][accept*="image"]']);
  assert.deepEqual(platform.coverFlow.retryableConfirmTexts, ['封面裁剪处理中，请稍后再点击“确定”']);
  assert.equal(platform.coverFlow.maxConfirmAttempts, 3);
  assert.deepEqual(platform.requiredUncheckedOptions, [
    {
      text: '自动生成视频',
      checkedEvidenceSelectors: [
        '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox.one-checkbox-checked',
      ],
      uncheckedEvidenceSelectors: [
        '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox:not(.one-checkbox-checked)',
      ],
    },
    {
      text: '自动生成播客',
      checkedEvidenceSelectors: [
        '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox.one-checkbox-checked',
      ],
      uncheckedEvidenceSelectors: [
        '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox:not(.one-checkbox-checked)',
      ],
    },
  ]);
});

test('baijiahao clears and positively verifies derivative options before simulation', async () => {
  const page = baijiahaoDerivativeOptionsPage();
  const platform = {
    ...simulatedPlatform(),
    requiredUncheckedOptions: getPlatform('baijiahao').requiredUncheckedOptions,
  };
  let submitAttempts = 0;

  const result = await publishWithBrowser(page, 'baijiahao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '百家号衍生内容关闭测试',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100, {
    onSubmitAttempt: async () => { submitAttempts += 1; },
  });

  assert.equal(submitAttempts, 0);
  assert.deepEqual(page.clicks(), ['自动生成视频', '自动生成播客']);
  assert.deepEqual(result.remote_meta.required_unchecked_options_verification, {
    required: true,
    platform: 'baijiahao',
    all_unchecked: true,
    options: [
      {
        text: '自动生成视频',
        unchecked: true,
        changed: true,
        evidence: {
          attribute: 'selector_state',
          value: '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox:not(.one-checkbox-checked)',
        },
      },
      {
        text: '自动生成播客',
        unchecked: true,
        changed: true,
        evidence: {
          attribute: 'selector_state',
          value: '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox:not(.one-checkbox-checked)',
        },
      },
    ],
  });
});

test('baijiahao falls back to the unique native checkbox state when CSS evidence changes', async () => {
  const page = baijiahaoDerivativeOptionsPage({ useNativeStateFallback: true });
  const platform = {
    ...simulatedPlatform(),
    requiredUncheckedOptions: getPlatform('baijiahao').requiredUncheckedOptions,
  };

  const result = await publishWithBrowser(page, 'baijiahao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '百家号原生开关回退测试',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.deepEqual(page.clicks(), ['自动生成视频', '自动生成播客']);
  assert.deepEqual(
    result.remote_meta.required_unchecked_options_verification.options.map((option) => option.evidence),
    [
      { attribute: 'checked', value: false },
      { attribute: 'checked', value: false },
    ],
  );
});

test('baijiahao preserves already disabled derivative options without clicking them', async () => {
  const page = baijiahaoDerivativeOptionsPage({ initiallyUnchecked: true });
  const platform = {
    ...simulatedPlatform(),
    requiredUncheckedOptions: getPlatform('baijiahao').requiredUncheckedOptions,
  };

  const result = await publishWithBrowser(page, 'baijiahao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '百家号衍生内容已关闭测试',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.equal(page.clicks().filter((text) => text === '自动生成视频').length, 0);
  assert.equal(page.clicks().filter((text) => text === '自动生成播客').length, 0);
  assert.deepEqual(result.remote_meta.required_unchecked_options_verification, {
    required: true,
    platform: 'baijiahao',
    all_unchecked: true,
    options: [
      {
        text: '自动生成视频',
        unchecked: true,
        changed: false,
        evidence: {
          attribute: 'selector_state',
          value: '.one-checkbox-wrapper:has-text("自动生成视频") .one-checkbox:not(.one-checkbox-checked)',
        },
      },
      {
        text: '自动生成播客',
        unchecked: true,
        changed: false,
        evidence: {
          attribute: 'selector_state',
          value: '.one-checkbox-wrapper:has-text("自动生成播客") .one-checkbox:not(.one-checkbox-checked)',
        },
      },
    ],
  });
});

test('baijiahao fails closed before submit when a derivative option cannot be verified off', async () => {
  const page = baijiahaoDerivativeOptionsPage({ stuckOption: '自动生成播客' });
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
    requiredUncheckedOptions: getPlatform('baijiahao').requiredUncheckedOptions,
  };
  let submitAttempts = 0;

  await assert.rejects(
    publishWithBrowser(page, 'baijiahao', platform, {
      publish_mode: 'publish',
      payload: {
        article: {
          title: '百家号衍生内容失败关闭测试',
          content: '正文',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 20, {
      onSubmitAttempt: async () => { submitAttempts += 1; },
    }),
    (error) => error?.code === 'manual_action_required'
      && /自动生成播客.*无法确认已关闭/.test(error.message),
  );
  assert.equal(submitAttempts, 0);
});

test('baijiahao navigation recovery rejects an existing-article editor URL', () => {
  assert.equal(
    editorLocationMatches(
      'https://baijiahao.baidu.com/builder/rc/edit?type=news&id=123',
      'https://baijiahao.baidu.com/builder/rc/edit?type=news',
    ),
    false,
  );
});

test('baijiahao clicks the exact publish control regardless of scheduled publish order', async () => {
  const platform = getPlatform('baijiahao');
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  assert.equal(platform.publishControlMode, 'exact_text');

  for (const roleOrder of [
    ['发布', '定时发布'],
    ['定时发布', '发布'],
  ]) {
    const clicks = [];
    const controls = Object.fromEntries(roleOrder.map((text) => [text, {
      isVisible: async () => true,
      isEnabled: async () => true,
      boundingBox: async () => ({ x: 100, y: 100, width: 120, height: 44 }),
      evaluate: async () => false,
      click: async () => { clicks.push(text); },
    }]));
    const page = {
      frames: () => [],
      getByRole: (role, options) => {
        assert.equal(role, 'button');
        assert.deepEqual(options, { name: '发布', exact: false });
        return candidates(roleOrder.map((text) => controls[text]));
      },
      getByText: (text, options) => {
        assert.equal(text, '发布');
        assert.deepEqual(options, { exact: true });
        return candidates([controls['发布']]);
      },
      waitForTimeout: async () => {},
    };

    assert.equal(await clickPublishControl(page, platform, ['发布'], 1000), true);
    assert.deepEqual(clicks, ['发布']);
  }
});

test('sohu uses its visible exact-text publish control', () => {
  const platform = getPlatform('sohu');

  assert.equal(platform.publishControlMode, 'exact_text');
  assert.deepEqual(platform.outcomeNoticeSelectors, ['.ant-message-notice-content']);
  assert.deepEqual(platform.reviewingNoticeTexts, ['已发布']);
  assert.deepEqual(platform.publishTexts, ['发布']);
});

test('AI-generated publishing only configures positively observed native disclosures', () => {
  assert.deepEqual(getPlatform('toutiao').aiDisclosure, {
    optionTexts: ['引用AI'],
  });
  assert.deepEqual(getPlatform('zhihu').aiDisclosure, {
    mode: 'select_value',
    triggerTexts: ['无声明'],
    optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueSelectors: [
      'button[aria-haspopup]:has-text("包含 AI 辅助创作 作者对内容负责")',
      '[role="combobox"]:has-text("包含 AI 辅助创作 作者对内容负责")',
    ],
    unselectedValueTexts: ['无声明'],
  });
  assert.deepEqual(getPlatform('sohu').aiDisclosure, {
    optionTexts: ['包含AI创作内容'],
  });
  assert.deepEqual(getPlatform('baijiahao').aiDisclosure, {
    optionTexts: ['采用AI生成内容'],
    selectedEvidenceSelectors: [
      '.one-checkbox-wrapper:has-text("采用AI生成内容") .one-checkbox.one-checkbox-checked',
    ],
  });
});

test('browser publishing rejects an invalid publish mode before opening the platform editor', async () => {
  let navigationCount = 0;
  const page = {
    url: () => 'about:blank',
    goto: async () => {
      navigationCount += 1;
    },
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', simulatedPlatform(), {
      publish_mode: 'unexpected',
      payload: {
        article: {
          title: 'Invalid mode',
          content: 'Body',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error?.code === 'invalid_payload' && error?.status === 422,
  );
  assert.equal(navigationCount, 0);
});

test('browser publishing rejects a missing publish mode before opening the platform editor', async () => {
  let navigationCount = 0;
  const page = {
    url: () => 'about:blank',
    goto: async () => {
      navigationCount += 1;
    },
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', simulatedPlatform(), {
      payload: {
        article: {
          title: 'Missing mode',
          content: 'Body',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error?.code === 'invalid_payload' && error?.status === 422,
  );
  assert.equal(navigationCount, 0);
});

test('runner rejects an invalid plain source names approval before opening the browser', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-source-approval-'));
  let browserOpened = false;
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(directory, 'STOP'),
    screenshotsDir: directory,
    operationTimeoutMs: 100,
  }, new StateStore(path.join(directory, 'state.json')), { write() {} });
  runner.context = async () => {
    browserOpened = true;
    throw new Error('Browser must not open for an invalid approval.');
  };

  await assert.rejects(
    runner.publish({
      platform: 'sohu',
      account_id: 'default',
      idempotency_key: 'invalid-source-approval',
      publish_mode: 'simulate',
      plain_source_names_approval: plainSourceNamesApproval({ payload_hash: 'ABC123' }),
      payload: {
        article: {
          title: '无效批准格式',
          content: '正文',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }),
    (error) => error.code === 'invalid_payload' && error.status === 422,
  );
  assert.equal(browserOpened, false);
});

test('AI-generated browser publishing fails closed when native disclosure is not configured', async () => {
  const page = simulatedEditorPage();
  const platform = simulatedPlatform();

  await assert.rejects(
    publishWithBrowser(page, 'baijiahao', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: 'AI disclosure test',
          content: 'Body',
          is_ai_generated: true,
        },
        assets: { images: [] },
      },
    }, 100),
    /AI 声明自动化配置不完整/,
  );
});

test('AI-generated simulated publishing returns verified native disclosure evidence', async () => {
  const disclosureText = '\u5305\u542b AI \u8f85\u52a9\u521b\u4f5c';
  const page = simulatedEditorPage(disclosureText);
  const platform = simulatedPlatform({
    optionTexts: [disclosureText],
  });

  const result = await publishWithBrowser(page, 'zhihu', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: 'AI disclosure test',
        content: 'Body',
        is_ai_generated: true,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.deepEqual(result.remote_meta.ai_disclosure_verification, {
    required: true,
    platform: 'zhihu',
    selected: true,
    option_text: disclosureText,
    evidence: {
      attribute: 'checked',
      value: true,
    },
  });
});

test('simulated publishing recovers when navigation reports an error after the editor is usable', async () => {
  const page = simulatedEditorPage('', {
    navigationError: new Error('page.goto: net::ERR_ABORTED'),
  });

  const result = await publishWithBrowser(page, 'baijiahao', simulatedPlatform(), {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: 'Recoverable navigation',
        content: 'Body',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.deepEqual(result.remote_meta.navigation_recovery, {
    recovered: true,
    issue: 'failed',
    current_url: 'https://example.test/editor',
  });
});

test('simulated publishing does not recover a navigation error on the wrong page', async () => {
  const navigationError = new Error('page.goto: net::ERR_ABORTED');
  const page = simulatedEditorPage('', {
    navigationError,
    navigationReachesTarget: false,
  });

  await assert.rejects(
    publishWithBrowser(page, 'baijiahao', simulatedPlatform(), {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: 'Wrong-page navigation',
          content: 'Body',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error === navigationError,
  );
});

test('simulated publishing fails closed when source links become plain text', async () => {
  const page = simulatedRichEditorPage();
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '来源链接保留测试',
          content: '正文\n\n官方依据',
          content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">官方来源</a></p>',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    /未保留.*链接/,
  );
});

test('simulated publishing records positive evidence when source links stay clickable', async () => {
  const page = simulatedRichEditorPage({
    directLinks: ['https://example.test/source'],
  });
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
  };

  const result = await publishWithBrowser(page, 'zhihu', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '来源链接保留测试',
        content: '正文\n\n官方依据',
        content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">官方来源</a></p>',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.deepEqual(result.remote_meta.content_verification.links, {
    required: true,
    ok: true,
    expectedCount: 1,
    actualCount: 1,
    matchedCount: 1,
    missingCount: 0,
    expectedUrls: ['https://example.test/source'],
    actualUrls: ['https://example.test/source'],
    missingUrls: [],
  });
});

test('a platform can require direct rich insertion when clipboard paste strips links', async () => {
  const page = simulatedRichEditorPage({
    clipboardAccepted: true,
    clipboardLinks: [],
    directLinks: ['https://example.test/source'],
  });
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  const result = await publishWithBrowser(page, 'baijiahao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '来源链接保留测试',
        content: '正文\n\n官方依据',
        content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">官方来源</a></p>',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.equal(result.remote_meta.content_verification.links.ok, true);
});

test('toutiao uses direct rich insertion when clipboard paste strips links', async () => {
  const source = 'https://example.test/source';
  const page = simulatedRichEditorPage({
    clipboardAccepted: true,
    clipboardLinks: [],
    directLinks: [source],
  });
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: getPlatform('toutiao').richInsertMode,
  };

  const result = await publishWithBrowser(page, 'toutiao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '头条来源链接保留测试',
        content: '正文\n\n官方依据',
        content_html: `<p>正文</p><h2>官方依据</h2><p><a href="${source}">官方来源</a></p>`,
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.remote_meta.content_verification.links.ok, true);
});

test('approved Sohu policy verifies source names when the editor strips source links', async () => {
  const page = simulatedRichEditorPage();
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'simulate',
    plain_source_names_approval: plainSourceNamesApproval(),
    payload: {
      article: {
        title: '搜狐来源名称验证',
        content: '正文\n\n官方依据\n\n人力资源和社会保障部',
        content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">人力资源和社会保障部</a></p>',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.deepEqual(result.remote_meta.content_verification.links, {
    required: true,
    ok: false,
    expectedCount: 1,
    actualCount: 0,
    matchedCount: 0,
    missingCount: 1,
    expectedUrls: ['https://example.test/source'],
    actualUrls: [],
    missingUrls: ['https://example.test/source'],
    plain_source_names: {
      ok: true,
      platform: 'sohu',
      article_distribution_id: 5,
      payload_hash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      expectedNames: ['人力资源和社会保障部'],
      actualNames: ['人力资源和社会保障部'],
      missingNames: [],
      expectedCount: 1,
      actualCount: 1,
      matchedCount: 1,
      missingCount: 0,
    },
  });
});

test('approved Sohu policy fails closed when a stripped link source name is absent', async () => {
  const page = simulatedRichEditorPage({ directBody: '正文\n官方依据' });
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'simulate',
      plain_source_names_approval: plainSourceNamesApproval(),
      payload: {
        article: {
          title: '搜狐来源名称缺失',
          content: '正文\n\n官方依据\n\n人力资源和社会保障部',
          content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">人力资源和社会保障部</a></p>',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && /来源名称/.test(error.message)
      && error.details?.content_verification?.links?.ok === false
      && error.details?.content_verification?.links?.plain_source_names?.ok === false
      && error.details.content_verification.links.plain_source_names.missingNames[0] === '人力资源和社会保障部',
  );
});

test('approved Sohu policy rejects a generic link label as an identifiable source name', async () => {
  const page = simulatedRichEditorPage();
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'simulate',
      plain_source_names_approval: plainSourceNamesApproval(),
      payload: {
        article: {
          title: '搜狐来源泛词拒绝',
          content: '正文\n\n官方依据\n\n官方来源',
          content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">官方来源</a></p>',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.content_verification?.links?.plain_source_names?.ok === false
      && error.details.content_verification.links.plain_source_names.missingNames[0] === '官方来源',
  );
});

test('Sohu without approval still requires source links', async () => {
  const page = simulatedRichEditorPage();
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '搜狐未批准链接校验',
          content: '正文\n\n官方依据\n\n人力资源和社会保障部',
          content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">人力资源和社会保障部</a></p>',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    /未保留 1 个正文链接/,
  );
});

test('plain source name approval never relaxes source links for another platform', async () => {
  const page = simulatedRichEditorPage();
  const platform = {
    ...simulatedPlatform(),
    editorFormat: undefined,
    richInsertMode: 'dom',
  };

  await assert.rejects(
    publishWithBrowser(page, 'zhihu', platform, {
      publish_mode: 'simulate',
      plain_source_names_approval: plainSourceNamesApproval(),
      payload: {
        article: {
          title: '其他平台保持链接校验',
          content: '正文\n\n官方依据\n\n人力资源和社会保障部',
          content_html: '<p>正文</p><h2>官方依据</h2><p><a href="https://example.test/source">人力资源和社会保障部</a></p>',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    /未保留 1 个正文链接/,
  );
});

test('simulated publishing prefers the unique configured editor overlay close control', async () => {
  const page = simulatedContentEditablePageWithOverlay({ closeControlCount: 1 });
  const platform = {
    ...simulatedPlatform(),
    editorOverlaySelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask'],
    editorOverlayCloseSelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn'],
  };

  const result = await publishWithBrowser(page, 'toutiao', platform, {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '编辑器遮罩显式关闭',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.equal(page.closeClicks(), 1);
  assert.equal(page.editorClicks(), 1);
});

test('simulated publishing fails closed when multiple editor overlay close controls are visible', async () => {
  const page = simulatedContentEditablePageWithOverlay({ closeControlCount: 2 });
  const platform = {
    ...simulatedPlatform(),
    editorOverlaySelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask'],
    editorOverlayCloseSelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn'],
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '编辑器遮罩关闭控件歧义',
          content: '正文',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.visible_editor_overlay_close_controls === 2,
  );

  assert.equal(page.closeClicks(), 0);
  assert.equal(page.editorClicks(), 0);
});

test('simulated publishing fails closed when no editor overlay close control is visible', async () => {
  const page = simulatedContentEditablePageWithOverlay();
  const platform = {
    ...simulatedPlatform(),
    editorOverlaySelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask'],
    editorOverlayCloseSelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn'],
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '编辑器遮罩缺少关闭控件',
          content: '正文',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.visible_editor_overlay_close_controls === 0,
  );

  assert.equal(page.closeClicks(), 0);
  assert.equal(page.editorClicks(), 0);
});

test('simulated publishing fails closed when a configured editor overlay stays visible', async () => {
  const page = simulatedContentEditablePageWithOverlay({
    closeControlCount: 1,
    closeHidesOverlay: false,
  });
  const platform = {
    ...simulatedPlatform(),
    editorOverlaySelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask'],
    editorOverlayCloseSelectors: ['.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn'],
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', platform, {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '编辑器遮罩失败关闭',
          content: '正文',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.editor_overlay_still_visible === true,
  );

  assert.equal(page.closeClicks(), 1);
  assert.equal(page.editorClicks(), 0);
});

test('verification wording inside editor content is not treated as a platform captcha', async () => {
  const page = publishingPageWithEditorBody();
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };

  const result = await publishWithBrowser(page, 'toutiao', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '验证码误判回归',
        content: '员工可以使用手机短信验证码完成身份核验。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'published');
  assert.equal(result.remote_meta.evidence_source, 'platform_success_url');
});

test('simulated Sohu publishing ignores ambiguous security text when editors are usable', async () => {
  const page = publishingPageWithEditorBody('搜狐号创作中心 安全验证');

  const result = await publishWithBrowser(page, 'sohu', simulatedPlatform(), {
    publish_mode: 'simulate',
    payload: {
      article: {
        title: '搜狐安全验证误判回归',
        content: '正文编辑器可用时应继续填稿。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'simulated');
  assert.equal(result.remote_meta.content_verification.title.ok, true);
  assert.equal(result.remote_meta.content_verification.body.ok, true);
});

test('simulated Sohu publishing preserves security blocking when editors are unavailable', async () => {
  const page = publishingPageWithEditorBody('请完成安全验证', '', false);

  await assert.rejects(
    publishWithBrowser(page, 'sohu', simulatedPlatform(), {
      publish_mode: 'simulate',
      payload: {
        article: {
          title: '搜狐真实安全验证拦截',
          content: '控件不可用时不得继续。',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.blockingText === '安全验证',
  );
});

test('Sohu publishing accepts a success URL despite residual ambiguous security text', async () => {
  const page = publishingPageWithEditorBody('搜狐号创作中心 安全验证');
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '搜狐提交结果误判回归',
        content: '明确成功 URL 应作为正向证据。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'published');
  assert.equal(result.remote_meta.evidence_source, 'platform_success_url');
});

test('Sohu publishing accepts explicit reviewing evidence despite residual ambiguous security text', async () => {
  const page = publishingPageWithEditorBody('安全验证 文章已进入审核');
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    reviewingTexts: ['文章已进入审核'],
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '搜狐审核结果误判回归',
        content: '明确审核文案应作为正向证据。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'reviewing');
  assert.equal(result.remote_meta.evidence_source, 'explicit_reviewing_text');
  assert.equal(result.remote_meta.evidence_text, '文章已进入审核');
});

test('Sohu treats its visible exact published toast as a reviewing submission', async () => {
  const page = publishingPageWithEditorBody(
    '搜狐号创作中心 安全验证 已发布 0 篇文章',
    '',
    true,
    ['已发布'],
  );
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    outcomeNoticeSelectors: ['.ant-message-notice-content'],
    reviewingNoticeTexts: ['已发布'],
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '搜狐发布提示识别回归',
        content: '只有可见且精确匹配的搜狐发布提示才可作为已提交审核证据。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'reviewing');
  assert.equal(result.remote_meta.evidence_source, 'explicit_reviewing_text');
  assert.equal(result.remote_meta.evidence_text, '已发布');
});

test('Sohu waits through its processing state before accepting the published toast', async () => {
  const page = publishingPageWithEditorBody(
    '搜狐号创作中心 安全验证 请稍等……',
    '',
    true,
    ['已发布'],
    1,
  );
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    outcomeNoticeSelectors: ['.ant-message-notice-content'],
    reviewingNoticeTexts: ['已发布'],
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '搜狐处理状态等待回归',
        content: '提交后的处理中状态不应在成功提示出现前被过早判定为失败。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'reviewing');
  assert.equal(result.remote_meta.evidence_text, '已发布');
});

test('Sohu does not treat generic page text as its published toast', async () => {
  const page = publishingPageWithEditorBody('搜狐号创作中心 安全验证 已发布');
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    outcomeNoticeSelectors: ['.ant-message-notice-content'],
    reviewingNoticeTexts: ['已发布'],
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'publish',
      payload: {
        article: {
          title: '搜狐普通状态文本不能当成功提示',
          content: '只有成功提示容器中的精确文字才可以作为提交证据。',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.blockingText === '安全验证',
  );
});

test('Sohu publishing still blocks residual ambiguous security text without positive outcome evidence', async () => {
  const page = publishingPageWithEditorBody('搜狐号创作中心 安全验证');
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'publish',
      payload: {
        article: {
          title: '搜狐提交结果保持阻断',
          content: '缺少正向证据时不得判定成功。',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.blockingText === '安全验证',
  );
});

test('Sohu publishing never lets success evidence override an explicit post-submit blocker', async () => {
  const page = publishingPageWithEditorBody(
    (currentUrl) => currentUrl.endsWith('/published') ? '安全验证 异常登录 发布成功' : '',
  );
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successTexts: ['发布成功'],
    successUrlPatterns: [/\/published$/],
  };

  await assert.rejects(
    publishWithBrowser(page, 'sohu', platform, {
      publish_mode: 'publish',
      payload: {
        article: {
          title: '搜狐明确阻断保持优先',
          content: '异常登录不得被成功证据覆盖。',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.blockingText === '异常登录',
  );
});

test('a generic captcha noun in mirrored article copy is not a platform blocker', () => {
  const platform = getPlatform('toutiao');
  const mirroredArticleCopy = [
    '草稿保存中',
    '单一短信验证码可以是证据的一部分，但不能直接表述为在所有场景下都足够。',
    '预览并发布',
  ].join('\n');

  assert.equal(findBlockingText(mirroredArticleCopy, platform), '');
  assert.equal(findBlockingText('请完成验证码后继续', platform), '请完成验证');
});

test('rich editor whitespace differences do not expose article verification wording as page state', async () => {
  const page = publishingPageWithEditorBody(
    '',
    '员工可以使用手机\n短信验证码完成身份核验。',
  );
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };

  const result = await publishWithBrowser(page, 'toutiao', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '富文本验证码误判回归',
        content: '员工可以使用手机短信验证码完成身份核验。',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'published');
  assert.equal(result.remote_meta.evidence_source, 'platform_success_url');
});

test('verification prompt outside editor content still blocks publishing', async () => {
  const page = publishingPageWithEditorBody('请完成验证');
  const platform = {
    ...simulatedPlatform(),
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };

  await assert.rejects(
    publishWithBrowser(page, 'toutiao', platform, {
      publish_mode: 'publish',
      payload: {
        article: {
          title: '验证码拦截回归',
          content: '员工可以使用手机短信验证码完成身份核验。',
          is_ai_generated: false,
        },
        assets: { images: [] },
      },
    }, 100),
    (error) => error.code === 'manual_action_required'
      && error.details?.blockingText === '请完成验证',
  );
});

test('toutiao requires an uploaded cover and never selects no-cover mode', () => {
  const platform = getPlatform('toutiao');

  assert.equal(platform.requiresCover, true);
  assert.equal(platform.optionalActions, undefined);
  assert.deepEqual(platform.coverFlow.triggerSelectors, ['.article-cover-add']);
  assert.deepEqual(platform.coverFlow.scopeSelectors, ['.upload-image-panel']);
  assert.deepEqual(platform.coverFlow.fileInputSelectors, ['.upload-btn input[type="file"]']);
});

function baijiahaoDerivativeOptionsPage({
  stuckOption = null,
  initiallyUnchecked = false,
  useNativeStateFallback = false,
} = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  const states = new Map([
    ['自动生成视频', !initiallyUnchecked],
    ['自动生成播客', !initiallyUnchecked],
  ]);
  const clicks = [];
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async () => title,
  };
  const editorControl = {
    isVisible: async () => true,
    fill: async (value) => { body = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : body,
  };
  const optionControls = Object.fromEntries([...states.keys()].map((text) => [text, {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 100, y: text === '自动生成视频' ? 500 : 550, width: 140, height: 36 }),
    evaluate: async () => useNativeStateFallback
      ? {
          state: states.get(text) ? 'checked' : 'unchecked',
          evidence: { attribute: 'checked', value: states.get(text) },
        }
      : null,
    click: async () => {
      clicks.push(text);
      if (text !== stuckOption) {
        states.set(text, false);
      }
    },
  }]));
  const publishControl = {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 900, y: 850, width: 120, height: 44 }),
    evaluate: async () => false,
    click: async () => { currentUrl = 'https://example.test/published'; },
  };

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async () => {},
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => body },
        ]);
      }
      if (selector === '#title') {
        return candidates([titleControl]);
      }
      if (selector === '#editor') {
        return candidates([editorControl]);
      }
      for (const [text, checked] of states.entries()) {
        if (!selector.includes(`:has-text("${text}")`)) {
          continue;
        }
        if (useNativeStateFallback) {
          return empty;
        }
        const asksForUnchecked = selector.includes(':not(.one-checkbox-checked)');
        if ((asksForUnchecked && !checked) || (!asksForUnchecked && checked)) {
          return candidates([{
            isVisible: async () => true,
            boundingBox: async () => ({ x: 100, y: text === '自动生成视频' ? 500 : 550, width: 24, height: 24 }),
          }]);
        }
        return empty;
      }
      return empty;
    },
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      if (optionControls[text]) {
        return candidates([optionControls[text]]);
      }
      return text === '发布' ? candidates([publishControl]) : empty;
    },
    clicks: () => clicks,
  };
}

function simulatedPlatform(aiDisclosure) {
  return {
    label: '测试平台',
    loginUrl: 'https://example.test/login',
    publishUrl: 'https://example.test/editor',
    titleSelectors: ['#title'],
    editorSelectors: ['#editor'],
    editorFormat: 'markdown',
    publishTexts: ['发布'],
    ...(aiDisclosure ? { aiDisclosure } : {}),
  };
}

function plainSourceNamesApproval(overrides = {}) {
  return {
    approved: true,
    platform: 'sohu',
    article_distribution_id: 5,
    approved_by: 'user:hanqijian',
    approved_at: '2026-07-29T12:00:00+08:00',
    payload_hash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ...overrides,
  };
}

function simulatedEditorPage(disclosureText = '', {
  navigationError = null,
  navigationReachesTarget = true,
} = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  const disclosureNode = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async () => title,
  };
  const editorControl = {
    isVisible: async () => true,
    fill: async (value) => { body = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : body,
  };
  const disclosureControl = {
    isVisible: async () => true,
    click: async () => { disclosureNode.checked = true; },
    evaluate: async (callback) => callback(disclosureNode),
  };
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);

  return {
    url: () => currentUrl,
    goto: async (url) => {
      if (navigationReachesTarget) {
        currentUrl = url;
      }
      if (navigationError) {
        throw navigationError;
      }
    },
    waitForTimeout: async () => {},
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => body },
        ]);
      }
      if (selector === '#title') {
        return candidates([titleControl]);
      }
      if (selector === '#editor') {
        return candidates([editorControl]);
      }
      return empty;
    },
    getByText: (text, options) => {
      if (options?.exact === false) {
        return empty;
      }
      assert.deepEqual(options, { exact: true });
      return disclosureText !== '' && text === disclosureText
        ? candidates([disclosureControl])
        : empty;
    },
  };
}

function simulatedRichEditorPage({
  clipboardAccepted = false,
  clipboardLinks = [],
  directLinks = [],
  directBody = null,
} = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  let actualLinks = [];
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async () => title,
  };
  const editorControl = {
    isVisible: async () => true,
    click: async () => {},
    press: async () => {},
    innerText: async () => body,
    evaluate: async (callback, value) => {
      const source = String(callback);
      if (source.includes('tagName.toLowerCase')) {
        return 'div';
      }
      if (source.includes("querySelectorAll('a[href]')")) {
        return actualLinks;
      }
      if (source.includes('execCommand')) {
        body = directBody ?? htmlToPlainText(value);
        actualLinks = directLinks;
        return;
      }
      return body;
    },
  };

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async () => {},
    frames: () => [],
    context: () => ({
      grantPermissions: async () => {},
    }),
    evaluate: async () => clipboardAccepted,
    keyboard: {
      press: async () => {
        body = '正文\n官方依据\n官方来源';
        actualLinks = clipboardLinks;
      },
    },
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => body },
        ]);
      }
      if (selector === '#title') {
        return candidates([titleControl]);
      }
      if (selector === '#editor') {
        return candidates([editorControl]);
      }
      return empty;
    },
    getByText: () => empty,
  };
}

function simulatedContentEditablePageWithOverlay({
  closeControlCount = 0,
  closeHidesOverlay = true,
} = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  let overlayVisible = true;
  let editorClicks = 0;
  let closeClicks = 0;
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async () => title,
  };
  const editorControl = {
    isVisible: async () => true,
    click: async () => {
      if (overlayVisible) {
        throw new Error('.byte-drawer-mask intercepts pointer events');
      }
      editorClicks += 1;
    },
    press: async () => {},
    evaluate: async (callback) => String(callback).includes('tagName') ? 'div' : body,
  };
  const overlayControl = {
    isVisible: async () => overlayVisible,
  };
  const closeControls = Array.from({ length: closeControlCount }, () => ({
    isVisible: async () => overlayVisible,
    click: async () => {
      closeClicks += 1;
      if (closeHidesOverlay) {
        overlayVisible = false;
      }
    },
  }));

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async () => {},
    frames: () => [],
    keyboard: {
      insertText: async (value) => { body = value; },
      press: async (key) => assert.notEqual(key, 'Escape', 'Editor overlay handling must not use Escape.'),
    },
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => body },
        ]);
      }
      if (selector === '#title') {
        return candidates([titleControl]);
      }
      if (selector === '#editor') {
        return candidates([editorControl]);
      }
      if (selector === '.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask') {
        return candidates([overlayControl]);
      }
      if (selector === '.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn') {
        return candidates(closeControls);
      }
      return empty;
    },
    getByText: () => empty,
    closeClicks: () => closeClicks,
    editorClicks: () => editorClicks,
  };
}

function publishingPageWithEditorBody(
  outsideText = '',
  renderedEditorText = '',
  controlsVisible = true,
  visibleNotices = [],
  noticeDelayChecks = 0,
) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  let noticeChecks = 0;
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : title,
  };
  const editorControl = {
    isVisible: async () => true,
    fill: async (value) => { body = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : body,
  };
  const publishControl = {
    isVisible: async () => true,
    click: async () => { currentUrl = 'https://example.test/published'; },
    evaluate: async (callback) => callback({ closest: () => null }),
  };
  const noticeControls = visibleNotices.map((text) => ({
    isVisible: async () => currentUrl.endsWith('/published'),
    innerText: async () => text,
  }));

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async () => {},
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => renderedEditorText || body },
          { text: () => typeof outsideText === 'function' ? outsideText(currentUrl) : outsideText },
        ]);
      }
      if (selector === '#title') {
        return controlsVisible ? candidates([titleControl]) : empty;
      }
      if (selector === '#editor') {
        return controlsVisible ? candidates([editorControl]) : empty;
      }
      if (selector === '.ant-message-notice-content') {
        return {
          count: async () => {
            noticeChecks += 1;
            return noticeChecks > noticeDelayChecks ? noticeControls.length : 0;
          },
          nth: (index) => noticeControls[index],
        };
      }
      return empty;
    },
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      return text === '发布' ? candidates([publishControl]) : empty;
    },
  };
}

function twoStagePublishingPage({ persistentGenericConfirmation = false } = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  let phase = 'preview';
  const clicks = [];
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : title,
  };
  const editorControl = {
    isVisible: async () => true,
    fill: async (value) => { body = value; },
    evaluate: async (callback) => String(callback).includes('tagName') ? 'textarea' : body,
  };
  const previewControl = {
    isVisible: async () => phase === 'preview',
    boundingBox: async () => ({ x: 900, y: 850, width: 120, height: 44 }),
    evaluate: async (callback) => callback({ closest: () => null }),
    click: async () => {
      clicks.push('预览并发布');
      phase = 'confirmation';
    },
  };
  const confirmationControl = {
    isVisible: async () => phase === 'confirmation' || (persistentGenericConfirmation && phase === 'published'),
    isEnabled: async () => true,
    boundingBox: async () => ({ x: 900, y: 850, width: 120, height: 44 }),
    evaluate: async (callback) => callback({ closest: () => null }),
    click: async () => {
      clicks.push('确认发布');
      phase = 'published';
      currentUrl = 'https://example.test/published';
    },
  };
  const confirmationDialog = {
    isVisible: async () => phase === 'confirmation' || (persistentGenericConfirmation && phase === 'published'),
    getByRole: (role, options) => role === 'button' && options.name === '确认发布'
      ? candidates([confirmationControl])
      : empty,
  };

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async () => {},
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return surfaceBodyLocator([
          { selector: '#title', text: () => title },
          { selector: '#editor', text: () => body },
        ]);
      }
      if (selector === '#title') {
        return candidates([titleControl]);
      }
      if (selector === '#editor') {
        return candidates([editorControl]);
      }
      if (persistentGenericConfirmation && selector === '[role="dialog"], .ant-modal, .semi-modal, .el-dialog') {
        return candidates([confirmationDialog]);
      }
      return empty;
    },
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      if (text === '预览并发布' && phase === 'preview') {
        return candidates([previewControl]);
      }
      if (!persistentGenericConfirmation && text === '确认发布' && phase === 'confirmation') {
        return candidates([confirmationControl]);
      }
      return empty;
    },
    clicks: () => clicks,
  };
}

function surfaceBodyLocator(parts) {
  const readText = () => parts.map((part) => part.text()).filter(Boolean).join('\n');

  return {
    innerText: async () => readText(),
    evaluate: async (callback, selectors) => {
      const clonedParts = parts.map((part) => ({
        selector: part.selector,
        text: part.text(),
        removed: false,
      }));
      const clone = {
        querySelectorAll: (selector) => clonedParts
          .filter((part) => !part.removed && part.selector === selector)
          .map((part) => ({
            remove: () => { part.removed = true; },
          })),
      };
      Object.defineProperties(clone, {
        innerText: {
          get: () => clonedParts.filter((part) => !part.removed).map((part) => part.text).filter(Boolean).join('\n'),
        },
        textContent: {
          get: () => clonedParts.filter((part) => !part.removed).map((part) => part.text).filter(Boolean).join('\n'),
        },
      });

      return callback({ cloneNode: () => clone }, selectors);
    },
  };
}

test('normalizes rich article content and removes executable html', () => {
  const platform = getPlatform('toutiao');
  const article = articleFromRequest({
    payload: { article: { title: '测试标题', content: '正文', content_html: '<h2>小节</h2><script>alert(1)</script><p onclick="bad()">正文</p>' } },
  }, platform);
  assert.equal(article.title, '测试标题');
  assert.match(article.html, /<h2>小节<\/h2>/);
  assert.doesNotMatch(article.html, /script|onclick/);
});

test('requires an embedded image for xiaohongshu posts', () => {
  assert.throws(() => uploadFilesFromRequest({ payload: { assets: { images: [] } } }, getPlatform('xiaohongshu')), /至少需要一张/);
  const files = uploadFilesFromRequest({
    payload: { assets: { images: [{ filename: 'cover.png', mime_type: 'image/png', content_base64: Buffer.from('png').toString('base64') }] } },
  }, getPlatform('xiaohongshu'));
  assert.equal(files.length, 1);
  assert.equal(files[0].name, 'cover.png');
});

test('xiaohongshu selects the image post tab before using an image-only upload control', async () => {
  const platform = getPlatform('xiaohongshu');
  const calls = [];
  const imageInput = {
    isVisible: async () => false,
    setInputFiles: async (files) => calls.push(['upload', files[0].name]),
  };
  const emptyCandidates = { count: async () => 0, nth: () => assert.fail('No empty candidate should be read.') };
  const page = {
    frames: () => [],
    getByText: (text) => text === '上传图文'
      ? {
          count: async () => 1,
          nth: () => ({
            isVisible: async () => true,
            click: async () => calls.push(['click', text]),
          }),
        }
      : emptyCandidates,
    locator: (selector) => selector.includes('accept*="image"')
      ? { count: async () => 1, nth: () => imageInput }
      : emptyCandidates,
    waitForTimeout: async () => {},
  };

  await prepareRequiredImageUpload(
    page,
    platform,
    [{ name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') }],
    1000,
  );

  assert.deepEqual(calls, [['click', '上传图文'], ['upload', 'cover.png']]);
  assert.equal(readinessControlsAreUsable(platform, {
    title_editor_visible: false,
    body_editor_visible: false,
    pre_upload_action_visible: true,
  }), true);
});

test('xiaohongshu collapses nested text nodes at the same visual upload target', async () => {
  const platform = getPlatform('xiaohongshu');
  const calls = [];
  const uploadTextCandidates = [
    {
      isVisible: async () => true,
      boundingBox: async () => ({ x: -9700, y: -9900, width: 64, height: 24 }),
      click: async () => calls.push('offscreen'),
    },
    {
      isVisible: async () => true,
      boundingBox: async () => ({ x: 500, y: 100, width: 64, height: 24 }),
      click: async () => calls.push('outer'),
    },
    {
      isVisible: async () => true,
      boundingBox: async () => ({ x: 484, y: 94.4, width: 80, height: 22.4 }),
      click: async () => calls.push('inner'),
    },
  ];
  const emptyCandidates = { count: async () => 0, nth: () => assert.fail('No empty candidate should be read.') };
  const page = {
    frames: () => [],
    getByText: (text) => text === '上传图文'
      ? { count: async () => 3, nth: (index) => uploadTextCandidates[index] }
      : emptyCandidates,
    locator: (selector) => selector.includes('accept*="image"')
      ? {
          count: async () => 1,
          nth: () => ({
            isVisible: async () => false,
            setInputFiles: async () => calls.push('upload'),
          }),
        }
      : emptyCandidates,
    waitForTimeout: async () => {},
  };

  await prepareRequiredImageUpload(
    page,
    platform,
    [{ name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') }],
    1000,
  );

  assert.deepEqual(calls, ['outer', 'upload']);
});

test('xiaohongshu can click its non-button publish control after checking disabled state', async () => {
  const platform = getPlatform('xiaohongshu');
  let clicked = false;
  const publishControl = {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 700, y: 850, width: 150, height: 50 }),
    evaluate: async () => false,
    click: async () => { clicked = true; },
  };
  const emptyCandidates = { count: async () => 0, nth: () => assert.fail('No empty candidate should be read.') };
  const page = {
    frames: () => [],
    getByText: (text) => text === '发布'
      ? { count: async () => 1, nth: () => publishControl }
      : emptyCandidates,
    waitForTimeout: async () => {},
  };

  assert.equal(await clickPublishControl(page, platform, ['发布'], 1000), true);
  assert.equal(clicked, true);
});

test('toutiao clicks its unique inline confirmation control after preview', async () => {
  const platform = getPlatform('toutiao');
  let clicked = false;
  const confirmationControl = {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 900, y: 850, width: 120, height: 44 }),
    evaluate: async () => false,
    click: async () => { clicked = true; },
  };
  const emptyCandidates = { count: async () => 0, nth: () => assert.fail('No empty candidate should be read.') };
  const page = {
    frames: () => [],
    locator: () => emptyCandidates,
    getByText: (text) => text === '确认发布'
      ? { count: async () => 1, nth: () => confirmationControl }
      : emptyCandidates,
    waitForTimeout: async () => {},
  };

  assert.equal(await clickConfirmationControl(page, platform, 1000), true);
  assert.equal(clicked, true);
});

test('toutiao refuses ambiguous inline confirmation controls', async () => {
  const platform = getPlatform('toutiao');
  let clickCount = 0;
  const candidates = [
    {
      isVisible: async () => true,
      boundingBox: async () => ({ x: 100, y: 850, width: 120, height: 44 }),
      evaluate: async () => false,
      click: async () => { clickCount += 1; },
    },
    {
      isVisible: async () => true,
      boundingBox: async () => ({ x: 900, y: 850, width: 120, height: 44 }),
      evaluate: async () => false,
      click: async () => { clickCount += 1; },
    },
  ];
  const emptyCandidates = { count: async () => 0, nth: () => assert.fail('No empty candidate should be read.') };
  const page = {
    frames: () => [],
    locator: () => emptyCandidates,
    getByText: (text) => text === '确认发布'
      ? { count: async () => candidates.length, nth: (index) => candidates[index] }
      : emptyCandidates,
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    clickConfirmationControl(page, platform, 1000),
    /多个不同位置/,
  );
  assert.equal(clickCount, 0);
});

test('toutiao publishing uses preview then one inline confirmation', async () => {
  const page = twoStagePublishingPage();
  const platform = {
    ...simulatedPlatform(),
    publishTexts: ['预览并发布'],
    publishControlMode: 'exact_text',
    confirmTexts: ['确认发布'],
    confirmControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };

  const result = await publishWithBrowser(page, 'toutiao', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '两阶段发布回归',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'published');
  assert.deepEqual(page.clicks(), ['预览并发布', '确认发布']);
});

test('generic publishing clicks a persistent confirmation control only once', async () => {
  const page = twoStagePublishingPage({ persistentGenericConfirmation: true });
  const platform = {
    ...simulatedPlatform(),
    publishTexts: ['预览并发布'],
    publishControlMode: 'exact_text',
    confirmTexts: ['确认发布'],
    successUrlPatterns: [/\/published$/],
  };

  const result = await publishWithBrowser(page, 'sohu', platform, {
    publish_mode: 'publish',
    payload: {
      article: {
        title: '单次确认回归',
        content: '正文',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  }, 100);

  assert.equal(result.status, 'published');
  assert.deepEqual(page.clicks(), ['预览并发布', '确认发布']);
});

test('requires a separate local cover without changing body image semantics', () => {
  const platform = getPlatform('toutiao');
  assert.throws(() => coverFileFromRequest({ payload: { assets: { images: [] } } }, platform), /需要一张本地封面图/);

  const request = {
    payload: {
      assets: {
        images: [
          { filename: 'cover.png', mime_type: 'image/png', content_base64: Buffer.from('cover').toString('base64') },
          { filename: 'body.png', mime_type: 'image/png', content_base64: Buffer.from('body').toString('base64') },
        ],
      },
    },
  };
  const cover = coverFileFromRequest(request, platform);

  assert.equal(cover.name, 'cover.png');
  assert.equal(cover.mimeType, 'image/png');
  assert.equal(cover.buffer.toString(), 'cover');
  assert.equal(uploadFilesFromRequest({ payload: { assets: { images: [] } } }, platform).length, 0);
});

test('baijiahao cover upload is scoped to one dialog and confirms before publishing', async () => {
  const platform = getPlatform('baijiahao');
  const coverFile = {
    name: 'cover.png',
    mimeType: 'image/png',
    buffer: Buffer.from('cover'),
  };
  const calls = [];
  let dialogVisible = true;
  let coverUploaded = false;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const trigger = {
    isVisible: async () => true,
    click: async () => calls.push('trigger-click'),
  };
  const input = {
    setInputFiles: async (file) => {
      coverUploaded = true;
      calls.push(['upload', file.name]);
    },
  };
  const confirm = {
    isVisible: async () => true,
    isEnabled: async () => coverUploaded,
    click: async () => {
      calls.push('confirm-click');
      dialogVisible = false;
    },
  };
  const dialog = {
    isVisible: async () => dialogVisible,
    locator: (selector) => {
      assert.equal(selector, platform.coverFlow.fileInputSelectors.join(', '));
      return { count: async () => 1, nth: () => input };
    },
    getByRole: (role, options) => {
      assert.equal(role, 'button');
      assert.equal(options.exact, false);
      return options.name === '确定' ? { count: async () => 1, nth: () => confirm } : empty;
    },
  };
  const page = {
    frames: () => [],
    getByText: (text, options) => {
      assert.equal(options.exact, true);
      return text === '选择封面' ? { count: async () => 1, nth: () => trigger } : empty;
    },
    locator: (selector) => {
      assert.equal(selector, platform.coverFlow.dialogSelectors.join(', '));
      return { count: async () => 1, nth: () => dialog };
    },
    waitForTimeout: async () => {},
  };

  const result = await applyRequiredCover(page, platform, coverFile, 1000);

  assert.deepEqual(calls, ['trigger-click', ['upload', 'cover.png'], 'confirm-click']);
  assert.deepEqual(result, {
    required: true,
    file_name: 'cover.png',
    mime_type: 'image/png',
    upload_accepted: true,
    dialog_closed: true,
  });
});

test('baijiahao replaces an existing cover through its unique change control', async () => {
  const platform = getPlatform('baijiahao');
  const calls = [];
  let dialogVisible = true;
  let coverUploaded = false;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const trigger = {
    isVisible: async () => true,
    click: async () => calls.push('change-cover'),
  };
  const dialog = {
    isVisible: async () => dialogVisible,
    locator: () => ({
      count: async () => 1,
      nth: () => ({
        setInputFiles: async (file) => {
          coverUploaded = true;
          calls.push(['upload', file.name]);
        },
      }),
    }),
    getByRole: (role, options) => options.name === '确定'
      ? {
          count: async () => 1,
          nth: () => ({
            isVisible: async () => true,
            isEnabled: async () => coverUploaded,
            click: async () => {
              calls.push('confirm');
              dialogVisible = false;
            },
          }),
        }
      : empty,
  };
  const page = {
    frames: () => [],
    getByText: (text) => text === '更换'
      ? { count: async () => 1, nth: () => trigger }
      : empty,
    locator: () => ({ count: async () => 1, nth: () => dialog }),
    waitForTimeout: async () => {},
  };

  const result = await applyRequiredCover(
    page,
    platform,
    { name: 'frozen-cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') },
    1000,
  );

  assert.deepEqual(calls, ['change-cover', ['upload', 'frozen-cover.png'], 'confirm']);
  assert.equal(result.upload_accepted, true);
  assert.equal(result.dialog_closed, true);
});

test('baijiahao retries cover confirmation only after the exact crop-processing notice', async () => {
  const platform = getPlatform('baijiahao');
  const coverFile = {
    name: 'cover.png',
    mimeType: 'image/png',
    buffer: Buffer.from('cover'),
  };
  const calls = [];
  let dialogVisible = true;
  let coverUploaded = false;
  let cropReady = false;
  let retryNoticeVisible = false;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const trigger = {
    isVisible: async () => true,
    click: async () => calls.push('trigger-click'),
  };
  const input = {
    setInputFiles: async (file) => {
      coverUploaded = true;
      calls.push(['upload', file.name]);
    },
  };
  const retryNotice = {
    isVisible: async () => retryNoticeVisible,
  };
  const confirm = {
    isVisible: async () => true,
    isEnabled: async () => coverUploaded,
    click: async () => {
      calls.push('confirm-click');
      if (cropReady) {
        dialogVisible = false;
      } else {
        retryNoticeVisible = true;
      }
    },
  };
  const dialog = {
    isVisible: async () => dialogVisible,
    locator: () => ({ count: async () => 1, nth: () => input }),
    getByRole: (role, options) => {
      assert.equal(role, 'button');
      return options.name === '确定' ? { count: async () => 1, nth: () => confirm } : empty;
    },
  };
  const page = {
    frames: () => [],
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      if (text === '选择封面') {
        return { count: async () => 1, nth: () => trigger };
      }
      if (text === '封面裁剪处理中，请稍后再点击“确定”' && retryNoticeVisible) {
        return { count: async () => 1, nth: () => retryNotice };
      }
      return empty;
    },
    locator: () => ({ count: async () => 1, nth: () => dialog }),
    waitForTimeout: async () => {
      if (retryNoticeVisible) {
        retryNoticeVisible = false;
        cropReady = true;
      }
    },
  };

  const result = await applyRequiredCover(page, platform, coverFile, 1000);

  assert.deepEqual(calls, ['trigger-click', ['upload', 'cover.png'], 'confirm-click', 'confirm-click']);
  assert.equal(result.dialog_closed, true);
  assert.equal(result.confirm_attempts, 2);
});

test('baijiahao does not blindly retry cover confirmation without the exact processing notice', async () => {
  const platform = getPlatform('baijiahao');
  let dialogVisible = true;
  let confirmAttempts = 0;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const dialog = {
    isVisible: async () => dialogVisible,
    locator: () => ({
      count: async () => 1,
      nth: () => ({ setInputFiles: async () => {} }),
    }),
    getByRole: (role, options) => options.name === '确定'
      ? {
          count: async () => 1,
          nth: () => ({
            isVisible: async () => true,
            isEnabled: async () => true,
            click: async () => { confirmAttempts += 1; },
          }),
        }
      : empty,
  };
  const page = {
    frames: () => [],
    getByText: (text) => text === '选择封面'
      ? {
          count: async () => 1,
          nth: () => ({ isVisible: async () => true, click: async () => {} }),
        }
      : empty,
    locator: () => ({ count: async () => 1, nth: () => dialog }),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyRequiredCover(
      page,
      platform,
      { name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') },
      20,
    ),
    (error) => error.code === 'manual_action_required' && /未在确认后关闭/.test(error.message),
  );
  assert.equal(dialogVisible, true);
  assert.equal(confirmAttempts, 1);
});

test('toutiao cover upload is scoped to its inline upload panel and confirms before publishing', async () => {
  const platform = getPlatform('toutiao');
  const coverFile = {
    name: 'cover.png',
    mimeType: 'image/png',
    buffer: Buffer.from('cover'),
  };
  const calls = [];
  let panelVisible = false;
  let coverUploaded = false;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const trigger = {
    isVisible: async () => true,
    click: async () => {
      calls.push('trigger-click');
      panelVisible = true;
    },
  };
  const input = {
    setInputFiles: async (file) => {
      coverUploaded = true;
      calls.push(['upload', file.name]);
    },
  };
  const confirm = {
    isVisible: async () => true,
    isEnabled: async () => coverUploaded,
    click: async () => {
      calls.push('confirm-click');
      panelVisible = false;
    },
  };
  const panel = {
    isVisible: async () => panelVisible,
    locator: (selector) => {
      assert.equal(selector, platform.coverFlow.fileInputSelectors.join(', '));
      return { count: async () => 1, nth: () => input };
    },
    getByRole: (role, options) => {
      assert.equal(role, 'button');
      assert.equal(options.exact, false);
      return options.name === '确定' ? { count: async () => 1, nth: () => confirm } : empty;
    },
  };
  const page = {
    frames: () => [],
    locator: (selector) => {
      if (selector === platform.coverFlow.triggerSelectors.join(', ')) {
        return { count: async () => 1, nth: () => trigger };
      }
      if (selector === platform.coverFlow.scopeSelectors.join(', ')) {
        return { count: async () => 1, nth: () => panel };
      }
      return empty;
    },
    waitForTimeout: async () => {},
  };

  const result = await applyRequiredCover(page, platform, coverFile, 1000);

  assert.deepEqual(calls, ['trigger-click', ['upload', 'cover.png'], 'confirm-click']);
  assert.deepEqual(result, {
    required: true,
    file_name: 'cover.png',
    mime_type: 'image/png',
    upload_accepted: true,
    dialog_closed: true,
  });
});

test('baijiahao cover flow aborts before clicking when the trigger is ambiguous', async () => {
  const platform = getPlatform('baijiahao');
  let clicked = false;
  const triggerCandidates = {
    count: async () => 2,
    nth: () => ({
      isVisible: async () => true,
      click: async () => { clicked = true; },
    }),
  };
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const page = {
    frames: () => [],
    getByText: (text) => text === '选择封面' ? triggerCandidates : empty,
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyRequiredCover(page, platform, { name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') }, 1000),
    (error) => error.code === 'manual_action_required' && /多个可见/.test(error.message),
  );
  assert.equal(clicked, false);
});

test('baijiahao cover flow aborts when the scoped dialog has multiple file inputs', async () => {
  const platform = getPlatform('baijiahao');
  let uploaded = false;
  const empty = { count: async () => 0, nth: () => assert.fail('Empty locator must not be read.') };
  const dialog = {
    isVisible: async () => true,
    locator: () => ({
      count: async () => 2,
      nth: () => ({
        setInputFiles: async () => { uploaded = true; },
      }),
    }),
  };
  const page = {
    frames: () => [],
    getByText: (text) => text === '选择封面'
      ? { count: async () => 1, nth: () => ({ isVisible: async () => true, click: async () => {} }) }
      : empty,
    locator: () => ({ count: async () => 1, nth: () => dialog }),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyRequiredCover(page, platform, { name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('cover') }, 1000),
    (error) => error.code === 'manual_action_required' && /多个候选图片控件/.test(error.message),
  );
  assert.equal(uploaded, false);
});

test('state store returns idempotent publishing results', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-'));
  const store = new StateStore(path.join(directory, 'state.json'));
  store.put('article-1', { ok: true, remote_id: 'remote-1' });
  const reloaded = new StateStore(path.join(directory, 'state.json'));
  assert.equal(reloaded.get('article-1').remote_id, 'remote-1');
});

test('account identifiers cannot escape the profile directory', () => {
  assert.equal(sanitizeIdentifier('company_account-1', 'account_id'), 'company_account-1');
  assert.throws(() => sanitizeIdentifier('../outside', 'account_id'), /只能包含/);
});

test('html sanitizer rejects javascript urls', () => {
  assert.equal(sanitizeArticleHtml('<a href="javascript:alert(1)">链接</a>'), '<a>链接</a>');
});

test('runner business endpoints only accept loopback and private network clients', () => {
  assert.equal(isPrivateAddress('127.0.0.1'), true);
  assert.equal(isPrivateAddress('::ffff:192.168.10.20'), true);
  assert.equal(isPrivateAddress('172.20.0.1'), true);
  assert.equal(isPrivateAddress('8.8.8.8'), false);
  assert.equal(isPrivateAddress('not-an-address'), false);
});

test('netease readiness reports account review without editing or submitting', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-readiness-'));
  const navigationCalls = [];
  const pollWaits = [];
  const screenshotCalls = [];
  const loggerEntries = [];
  let currentUrl = 'about:blank';
  const emptyCandidates = {
    count: async () => 0,
    nth: () => assert.fail('No empty locator candidate should be read.'),
  };
  const visibleCandidate = {
    isVisible: async () => true,
  };
  const page = {
    url: () => currentUrl,
    goto: async (url, options) => {
      currentUrl = url;
      navigationCalls.push({ url, options });
    },
    waitForTimeout: async (timeout) => pollWaits.push(timeout),
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return {
          ...emptyCandidates,
          ...surfaceBodyLocator([
            { text: () => '您的账号信息正在审核中 今日已发布 0/0' },
          ]),
        };
      }
      if (selector === 'input[type="file"]') {
        return { count: async () => 2, nth: () => visibleCandidate };
      }
      return emptyCandidates;
    },
    getByText: (text) => text === '设置封面'
      ? { count: async () => 1, nth: () => visibleCandidate }
      : emptyCandidates,
    screenshot: async (options) => screenshotCalls.push(options),
    click: async () => assert.fail('Readiness must not click.'),
    fill: async () => assert.fail('Readiness must not fill.'),
  };
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(directory, 'STOP'),
    screenshotsDir: directory,
    operationTimeoutMs: 30000,
  }, {}, { write: (...entry) => loggerEntries.push(entry) });
  runner.context = async () => ({});
  runner.page = async () => page;

  const result = await runner.readiness('netease', 'default');

  assert.equal(navigationCalls.length, 1);
  assert.equal(navigationCalls[0].url, getPlatform('netease').publishUrl);
  const { screenshot, ...readiness } = result;
  assert.deepEqual(readiness, {
    ok: true,
    ready: false,
    reason: '您的账号信息正在审核中',
    platform: 'netease',
    platform_label: '网易号',
    account_id: 'default',
    current_url: getPlatform('netease').publishUrl,
    title_editor_visible: false,
    body_editor_visible: false,
    file_input_count: 2,
    cover_setting_visible: true,
  });
  assert.match(screenshot, /netease-default-.*\.png$/);
  assert.equal(screenshotCalls.length, 1);
  assert.equal(screenshotCalls[0].path, screenshot);
  assert.equal(pollWaits.length, 0);
  assert.equal(runner.activeAccounts.size, 0);
  assert.equal('cookies' in result, false);
  assert.equal('storage' in result, false);
  assert.equal(loggerEntries.some((entry) => JSON.stringify(entry).includes('今日已发布')), false);
});

test('readiness polls until slow editors become visible without mutating the page', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-readiness-slow-'));
  const platform = getPlatform('netease');
  const pollWaits = [];
  let loadStep = 0;
  let currentUrl = 'about:blank';
  const emptyCandidates = {
    count: async () => 0,
    nth: () => assert.fail('No empty locator candidate should be read.'),
  };
  const visibleCandidates = {
    count: async () => 1,
    nth: () => ({ isVisible: async () => true }),
  };
  const page = {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    waitForTimeout: async (timeout) => {
      pollWaits.push(timeout);
      loadStep += 1;
    },
    frames: () => [],
    locator: (selector) => {
      if (selector === 'body') {
        return {
          ...emptyCandidates,
          ...surfaceBodyLocator([{ text: () => '网易号创作中心' }]),
        };
      }
      if (selector === 'input[type="file"]') {
        return loadStep >= 2 ? visibleCandidates : emptyCandidates;
      }
      if (selector === platform.titleSelectors[0]) {
        return loadStep >= 1 ? visibleCandidates : emptyCandidates;
      }
      if (selector === platform.editorSelectors[0]) {
        return loadStep >= 2 ? visibleCandidates : emptyCandidates;
      }
      return emptyCandidates;
    },
    getByText: (text) => text === '设置封面' && loadStep >= 2 ? visibleCandidates : emptyCandidates,
    screenshot: async () => assert.fail('Ready accounts must not be screenshotted.'),
    click: async () => assert.fail('Readiness must not click.'),
    fill: async () => assert.fail('Readiness must not fill.'),
  };
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(directory, 'STOP'),
    screenshotsDir: directory,
    operationTimeoutMs: 30000,
  }, {}, { write() {} });
  runner.context = async () => ({});
  runner.page = async () => page;

  const result = await runner.readiness('netease', 'default');

  assert.deepEqual(result, {
    ok: true,
    ready: true,
    reason: null,
    platform: 'netease',
    platform_label: '网易号',
    account_id: 'default',
    current_url: platform.publishUrl,
    title_editor_visible: true,
    body_editor_visible: true,
    file_input_count: 1,
    cover_setting_visible: true,
  });
  assert.deepEqual(pollWaits.length, 2);
  assert.equal(pollWaits.every((timeout) => timeout > 0 && timeout <= 300), true);
  assert.equal('screenshot' in result, false);
});

test('netease account review notice blocks publishing and is never treated as article review success', () => {
  const platform = getPlatform('netease');
  const pageText = '您的账号信息正在审核中 今日已发布0/0 共0字';

  assert.equal(findBlockingText(pageText, platform), '您的账号信息正在审核中');
  assert.equal(classifyOutcomeText(pageText, platform, 'publish'), '');
  assert.equal(platform.successTexts.includes('审核中'), false);
});

test('netease only recognizes explicit article publishing outcomes', () => {
  const platform = getPlatform('netease');

  assert.equal(classifyOutcomeText('文章已进入审核，请稍后查看', platform, 'publish'), 'reviewing');
  assert.equal(classifyOutcomeText('发布成功', platform, 'publish'), 'published');
  assert.equal(classifyOutcomeText('今日已发布0/0 共0字', platform, 'publish'), '');
  assert.deepEqual(classifyOutcomeEvidence('发布成功', platform, 'publish'), {
    status: 'published',
    source: 'explicit_success_text',
    text: '发布成功',
  });
});

test('broad status words are not publishing success evidence on any platform', () => {
  const forbiddenSuccessTexts = new Set(['审核中', '已发布', '发布', '成功']);
  for (const key of platformKeys()) {
    const platform = getPlatform(key);
    assert.equal(platform.successTexts.some((text) => forbiddenSuccessTexts.has(text)), false, key);
    assert.equal(classifyOutcomeText('审核中', platform, 'publish'), '', key);
    assert.equal(classifyOutcomeText('已发布 0 篇文章', platform, 'publish'), '', key);
  }
});

test('remote references stay null unless the current url proves a real platform id', () => {
  const platform = getPlatform('toutiao');
  const unavailable = extractRemoteReference(platform.publishUrl, platform);

  assert.deepEqual(unavailable, {
    id: null,
    url: null,
    source: null,
  });
  assert.deepEqual(JSON.parse(JSON.stringify({ remote_id: unavailable.id, remote_url: unavailable.url })), {
    remote_id: null,
    remote_url: null,
  });
  assert.deepEqual(extractRemoteReference('https://www.toutiao.com/article/123456789/', platform), {
    id: '123456789',
    url: 'https://www.toutiao.com/article/123456789/',
    source: 'public_url_pattern',
  });
});

test('sohu only accepts contentStatus=1 as platform success url evidence', () => {
  const platform = getPlatform('sohu');
  const beforePublish = 'https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle';
  const intermediate = 'https://mp.sohu.com/mpfe/v4/contentManagement/news/first/page';
  const succeeded = `${beforePublish}?contentStatus=1`;

  assert.equal(classifyPlatformSuccessUrlEvidence(beforePublish, platform, 'publish'), null);
  assert.equal(classifyPlatformSuccessUrlEvidence(intermediate, platform, 'publish'), null);
  assert.equal(classifyPlatformSuccessUrlEvidence(`${beforePublish}?contentStatus=10`, platform, 'publish'), null);
  assert.equal(classifyPlatformSuccessUrlEvidence(succeeded, platform, 'draft'), null);
  assert.deepEqual(classifyPlatformSuccessUrlEvidence(succeeded, platform, 'publish'), {
    status: 'published',
    source: 'platform_success_url',
    text: null,
  });
  assert.deepEqual(extractRemoteReference(succeeded, platform), {
    id: null,
    url: null,
    source: null,
  });
});

test('content integrity requires matching first and last anchors with a reasonable length', () => {
  const prefix = '第一段是正文开头，这里保留足够长的文字作为稳定首部锚点。';
  const middle = '中间包含需要完整写入编辑器的内容。';
  const suffix = '最后一段是正文结尾，这里也保留足够长的文字作为稳定尾部锚点。';
  const expected = `${prefix}${middle}${suffix}`;
  const complete = assessTextIntegrity(expected, `${prefix}\n${middle}\n${suffix}`);
  const truncated = assessTextIntegrity(expected, `${prefix}${middle}`);
  const duplicated = assessTextIntegrity(expected, `${expected}${expected}`);
  const corrupted = assessTextIntegrity(expected, `${prefix}${'错'.repeat(Array.from(middle).length)}${suffix}`);

  assert.equal(complete.ok, true);
  assert.equal(truncated.ok, false);
  assert.equal(truncated.lastAnchorMatched, false);
  assert.equal(duplicated.ok, false);
  assert.equal(duplicated.lengthReasonable, false);
  assert.equal(corrupted.ok, false);
  assert.equal(corrupted.firstAnchorMatched, true);
  assert.equal(corrupted.lastAnchorMatched, true);
  assert.equal(corrupted.lengthReasonable, true);
  assert.equal(corrupted.exactMatch, false);
});

test('article 12 actual publishing payload passes complete-body checks and rejects truncation', () => {
  const article = articleFromRequest({ payload: article12Payload }, getPlatform('csdn'));
  const checksum = createHash('sha256').update(article.markdown).digest('hex');
  const truncated = Array.from(article.markdown).slice(0, -100).join('');

  assert.equal(article12Payload.article.id, 12);
  assert.equal(checksum, '46ec73d8842599f871c738027a0b03991d2e3e6a8089f5aec608e579c220f8c2');
  assert.equal(assessTextIntegrity(article.markdown, article.markdown).ok, true);
  assert.equal(assessTextIntegrity(article.markdown, truncated).ok, false);
});

test('emergency stop closes active browser contexts and can be resumed', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-stop-'));
  let closed = false;
  const runner = new BrowserRunner({ enabled: true, stopPath: path.join(directory, 'STOP') }, {}, { write() {} });
  runner.contexts.set('toutiao:default', { close: async () => { closed = true; } });

  const stopped = await runner.stop();
  assert.equal(closed, true);
  assert.equal(stopped.stopped, true);
  assert.equal(stopped.enabled, false);

  const started = runner.start();
  assert.equal(started.stopped, false);
  assert.equal(started.enabled, true);
});
