import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { articleFromRequest, coverFileFromRequest, sanitizeArticleHtml, uploadFilesFromRequest } from '../src/content.js';
import { getPlatform, platformKeys } from '../src/platforms.js';
import { StateStore } from '../src/state-store.js';
import { BrowserRunner, sanitizeIdentifier } from '../src/browser-runner.js';
import {
  assessTextIntegrity,
  applyRequiredCover,
  classifyOutcomeEvidence,
  classifyOutcomeText,
  classifyPlatformSuccessUrlEvidence,
  clickPublishControl,
  extractRemoteReference,
  findBlockingText,
  prepareRequiredImageUpload,
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
  assert.deepEqual(platform.coverFlow.triggerTexts, ['选择封面']);
  assert.deepEqual(platform.coverFlow.fileInputSelectors, ['input[type="file"][accept*="image"]']);
});

test('toutiao requires an uploaded cover and never selects no-cover mode', () => {
  const platform = getPlatform('toutiao');

  assert.equal(platform.requiresCover, true);
  assert.equal(platform.optionalActions, undefined);
  assert.deepEqual(platform.coverFlow.triggerSelectors, ['.article-cover-add']);
  assert.deepEqual(platform.coverFlow.scopeSelectors, ['.upload-image-panel']);
  assert.deepEqual(platform.coverFlow.fileInputSelectors, ['.upload-btn input[type="file"]']);
});

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
          innerText: async () => '您的账号信息正在审核中 今日已发布 0/0',
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
        return { ...emptyCandidates, innerText: async () => '网易号创作中心' };
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
