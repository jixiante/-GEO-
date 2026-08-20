import assert from 'node:assert/strict';
import test from 'node:test';
import { articleFromRequest } from '../src/content.js';
import { ManualActionError } from '../src/errors.js';
import { getPlatform } from '../src/platforms.js';

function requestWithTitle(title) {
  return {
    payload: {
      article: {
        title,
        content: '正文',
      },
    },
  };
}

test('fails closed when a title exceeds the configured platform limit', () => {
  assert.throws(
    () => articleFromRequest(requestWithTitle('标'.repeat(31)), { maxTitleLength: 30 }),
    (error) => (
      error instanceof ManualActionError
      && error.message.includes('30')
      && error.message.includes('31')
    ),
  );
});

test('preserves a title exactly at the configured platform limit', () => {
  const title = '标'.repeat(30);

  const article = articleFromRequest(requestWithTitle(title), { maxTitleLength: 30 });

  assert.equal(article.title, title);
});

test('keeps the existing 120-character safety cap when a platform has no title limit', () => {
  const article = articleFromRequest(requestWithTitle('标'.repeat(121)), {});

  assert.equal(Array.from(article.title).length, 120);
});

test('uses Toutiao live title limit and its unique exact publish control', () => {
  const platform = getPlatform('toutiao');

  assert.equal(platform.maxTitleLength, 30);
  assert.deepEqual(platform.publishTexts, ['预览并发布']);
  assert.equal(platform.publishControlMode, 'exact_text');
  assert.deepEqual(platform.confirmTexts, ['确认发布']);
  assert.equal(platform.confirmControlMode, 'exact_text');
  assert.deepEqual(platform.editorOverlaySelectors, ['.byte-drawer-wrapper.ai-assistant-drawer .byte-drawer-mask']);
  assert.deepEqual(platform.editorOverlayCloseSelectors, [
    '.byte-drawer-wrapper.ai-assistant-drawer .ai-assistant-panel-in-drawer > .header > svg.close-btn',
  ]);
});
