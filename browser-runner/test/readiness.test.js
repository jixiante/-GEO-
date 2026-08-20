import assert from 'node:assert/strict';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { BrowserRunner } from '../src/browser-runner.js';
import { waitForReadinessState } from '../src/automation.js';
import { getPlatform } from '../src/platforms.js';

const platform = {
  loginUrl: 'https://mp.sohu.com/',
  titleSelectors: ['[data-test="title"]'],
  editorSelectors: ['[data-test="editor"]'],
};

test('readiness lets usable editors outrank non-blocking security text after loading', async () => {
  let controlsLoaded = false;
  const page = readinessPage({
    bodyText: '搜狐号创作中心 安全验证',
    controlVisible: () => controlsLoaded,
    waitForTimeout: async () => {
      controlsLoaded = true;
    },
  });

  const result = await waitForReadinessState(page, platform, 100);

  assert.equal(controlsLoaded, true);
  assert.deepEqual(result.pageState, {
    url: 'https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle',
    blockingText: '',
    looksLikeLogin: false,
    surfaceText: '搜狐号创作中心 安全验证',
  });
  assert.equal(result.controls.title_editor_visible, true);
  assert.equal(result.controls.body_editor_visible, true);
});

test('readiness preserves security blocking text when editing controls stay unavailable', async () => {
  const page = readinessPage({
    bodyText: '请完成安全验证',
    controlVisible: () => false,
    waitForTimeout: async () => {},
  });

  const result = await waitForReadinessState(page, platform, 1);

  assert.equal(result.pageState.blockingText, '安全验证');
  assert.equal(result.controls.title_editor_visible, false);
  assert.equal(result.controls.body_editor_visible, false);
});

test('readiness inspects usable editors after a navigation timeout instead of returning HTTP 500', async () => {
  const targetPlatform = getPlatform('baijiahao');
  const timeoutError = new Error('page.goto: Timeout 180000ms exceeded.');
  timeoutError.name = 'TimeoutError';
  const page = readinessPage({
    bodyText: '',
    controlVisible: () => true,
    waitForTimeout: async () => {},
    targetPlatform,
    url: targetPlatform.publishUrl,
    goto: async () => {
      throw timeoutError;
    },
  });
  const events = [];
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(os.tmpdir(), 'dianqian-readiness-test-stop'),
    operationTimeoutMs: 180000,
  }, {}, {
    write: (level, event, details = {}) => events.push({ level, event, details }),
  });
  runner.context = async () => ({});
  runner.page = async () => page;

  const result = await runner.readiness('baijiahao', 'dianqian_main');

  assert.equal(result.ready, true);
  assert.equal(result.navigation_timed_out, true);
  assert.equal(
    events.some(({ event }) => event === 'account.readiness_navigation_timed_out'),
    true,
  );
});

test('readiness inspects usable editors after a recoverable navigation error', async () => {
  const targetPlatform = getPlatform('baijiahao');
  const page = readinessPage({
    bodyText: '',
    controlVisible: () => true,
    waitForTimeout: async () => {},
    targetPlatform,
    url: targetPlatform.publishUrl,
    goto: async () => {
      throw new Error('page.goto: net::ERR_ABORTED');
    },
  });
  const events = [];
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(os.tmpdir(), 'dianqian-readiness-test-stop'),
    operationTimeoutMs: 180000,
  }, {}, {
    write: (level, event, details = {}) => events.push({ level, event, details }),
  });
  runner.context = async () => ({});
  runner.page = async () => page;

  const result = await runner.readiness('baijiahao', 'dianqian_main');

  assert.equal(result.ready, true);
  assert.equal(result.navigation_failed, true);
  assert.equal(
    events.some(({ event }) => event === 'account.readiness_navigation_failed'),
    true,
  );
});

test('readiness does not recover a navigation error on the wrong page', async () => {
  const targetPlatform = getPlatform('baijiahao');
  const page = readinessPage({
    bodyText: '',
    controlVisible: () => true,
    waitForTimeout: async () => {},
    targetPlatform,
    url: 'https://baijiahao.baidu.com/',
    goto: async () => {
      throw new Error('page.goto: net::ERR_ABORTED');
    },
  });
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(os.tmpdir(), 'dianqian-readiness-test-stop'),
    screenshotsDir: os.tmpdir(),
    operationTimeoutMs: 180000,
  }, {}, { write() {} });
  runner.context = async () => ({});
  runner.page = async () => page;

  const result = await runner.readiness('baijiahao', 'dianqian_main');

  assert.equal(result.ready, false);
  assert.equal(result.reason, 'navigation_failed');
});

function readinessPage({
  bodyText,
  controlVisible,
  waitForTimeout,
  targetPlatform = platform,
  url = 'https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle',
  goto = async () => {},
}) {
  const emptyCandidates = {
    count: async () => 0,
    nth: () => assert.fail('Empty candidates must not be inspected.'),
  };
  const visibleCandidates = {
    count: async () => controlVisible() ? 1 : 0,
    nth: () => ({ isVisible: async () => true }),
  };

  return {
    url: () => url,
    goto,
    frames: () => [],
    waitForTimeout,
    locator: (selector) => {
      if (selector === 'body') {
        return {
          ...emptyCandidates,
          evaluate: async () => bodyText,
        };
      }
      if (targetPlatform.titleSelectors.includes(selector) || targetPlatform.editorSelectors.includes(selector)) {
        return visibleCandidates;
      }
      return emptyCandidates;
    },
    getByText: () => emptyCandidates,
  };
}
