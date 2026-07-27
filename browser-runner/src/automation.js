import { ManualActionError } from './errors.js';
import { articleFromRequest, coverFileFromRequest, htmlToPlainText, uploadFilesFromRequest } from './content.js';

const blockingTexts = ['验证码', '安全验证', '异常登录', '账号存在风险', '请完成验证', '滑块验证', '扫码登录'];

export function findBlockingText(bodyText, platform) {
  const text = String(bodyText ?? '');
  return [...(platform.blockingTexts ?? []), ...blockingTexts].find((candidate) => text.includes(candidate)) ?? '';
}

export function classifyOutcomeEvidence(bodyText, platform, publishMode) {
  const text = String(bodyText ?? '');
  if (findBlockingText(text, platform) !== '') {
    return null;
  }

  if (publishMode === 'draft') {
    const draftTexts = ['草稿保存成功', '已保存到草稿', '保存成功'];
    const matchedDraftText = draftTexts.find((candidate) => text.includes(candidate));
    if (matchedDraftText) {
      return { status: 'draft', source: 'explicit_draft_text', text: matchedDraftText };
    }
  }

  const matchedReviewingText = (platform.reviewingTexts ?? []).find((candidate) => text.includes(candidate));
  if (publishMode === 'publish' && matchedReviewingText) {
    return { status: 'reviewing', source: 'explicit_reviewing_text', text: matchedReviewingText };
  }

  const matchedSuccessText = (platform.successTexts ?? []).find((candidate) => text.includes(candidate));
  if (matchedSuccessText) {
    return {
      status: publishMode === 'draft' ? 'draft' : 'published',
      source: 'explicit_success_text',
      text: matchedSuccessText,
    };
  }

  return null;
}

export function classifyOutcomeText(bodyText, platform, publishMode) {
  return classifyOutcomeEvidence(bodyText, platform, publishMode)?.status ?? '';
}

export function assessTextIntegrity(expectedText, actualText, options = {}) {
  const expected = normalizeComparableText(expectedText);
  const actual = normalizeComparableText(actualText);
  const expectedLength = Array.from(expected).length;
  const actualLength = Array.from(actual).length;
  const minLengthRatio = options.minLengthRatio ?? 0.9;
  const maxLengthRatio = options.maxLengthRatio ?? 1.15;
  const configuredAnchorLength = options.anchorLength ?? 24;
  const anchorLength = Math.min(expectedLength, Math.max(1, configuredAnchorLength));
  const firstAnchor = Array.from(expected).slice(0, anchorLength).join('');
  const lastAnchor = Array.from(expected).slice(-anchorLength).join('');
  const minimumLength = Math.max(1, Math.floor(expectedLength * minLengthRatio));
  const maximumLength = Math.max(minimumLength, Math.ceil(expectedLength * maxLengthRatio));
  const firstAnchorMatched = expectedLength > 0 && actual.startsWith(firstAnchor);
  const lastAnchorMatched = expectedLength > 0 && actual.endsWith(lastAnchor);
  const lengthReasonable = actualLength >= minimumLength && actualLength <= maximumLength;
  const exactMatch = expectedLength > 0 && expected === actual;

  return {
    ok: firstAnchorMatched && lastAnchorMatched && lengthReasonable && exactMatch,
    expectedLength,
    actualLength,
    minimumLength,
    maximumLength,
    firstAnchorMatched,
    lastAnchorMatched,
    lengthReasonable,
    exactMatch,
  };
}

export function extractRemoteReference(url, platform) {
  const currentUrl = String(url ?? '');
  for (const pattern of platform.publicUrlPatterns ?? []) {
    const match = currentUrl.match(pattern);
    if (match) {
      return {
        id: match[1] ? String(match[1]) : null,
        url: currentUrl || null,
        source: 'public_url_pattern',
      };
    }
  }
  return { id: null, url: null, source: null };
}

export function classifyPlatformSuccessUrlEvidence(url, platform, publishMode) {
  if (publishMode !== 'publish') {
    return null;
  }
  const currentUrl = String(url ?? '');
  const matched = (platform.successUrlPatterns ?? []).some((pattern) => pattern.test(currentUrl));
  return matched
    ? { status: 'published', source: 'platform_success_url', text: null }
    : null;
}

export async function publishWithBrowser(page, platformKey, platform, request, timeoutMs) {
  const article = articleFromRequest(request, platform);
  const files = uploadFilesFromRequest(request, platform);
  const publishMode = ['publish', 'draft', 'simulate'].includes(request.publish_mode) ? request.publish_mode : 'publish';
  const coverFile = publishMode !== 'draft' ? coverFileFromRequest(request, platform) : null;
  const originalUrl = page.url();

  await page.goto(platform.publishUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  await page.waitForTimeout(1500);

  await throwIfPageBlocked(page, platform);

  if (platform.requiresImage) {
    await prepareRequiredImageUpload(page, platform, files, timeoutMs);
    await page.waitForTimeout(1000);
  }

  const titleInput = await findVisible(page, platform.titleSelectors, 25000);
  if (!titleInput) {
    await throwPageStateError(page, platform);
  }
  await titleInput.fill(article.title);

  const editor = await findVisible(page, platform.editorSelectors, 15000);
  if (!editor) {
    await throwPageStateError(page, platform, '未找到正文编辑器，平台页面结构可能已经变化。');
  }
  await fillEditor(page, editor, article, platform.editorFormat, platform.requiresImage);
  const contentVerification = await verifyInsertedContent(page, titleInput, editor, article, platform);

  const coverVerification = coverFile
    ? await applyRequiredCover(page, platform, coverFile, Math.min(timeoutMs, 20000))
    : null;

  for (const text of platform.optionalActions ?? []) {
    await clickOptionalText(page, text);
  }

  if (publishMode === 'simulate') {
    return {
      ok: true,
      status: 'simulated',
      remote_id: null,
      remote_url: null,
      remote_meta: {
        current_url: page.url(),
        publish_mode: publishMode,
        title_truncated: article.title !== String(request?.payload?.article?.title ?? '').trim(),
        evidence_source: 'simulation_complete',
        evidence_text: null,
        remote_reference_source: 'unavailable',
        content_verification: contentVerification,
        cover_verification: coverVerification,
      },
    };
  }

  const publishTexts = publishMode === 'draft' ? ['保存草稿', '存草稿', '草稿'] : platform.publishTexts;
  const clicked = await clickPublishControl(page, platform, publishTexts, 12000);
  if (!clicked) {
    throw new ManualActionError(`未找到${publishMode === 'draft' ? '保存草稿' : '发布'}按钮，请在已打开的浏览器中检查页面。`);
  }

  if (publishMode === 'publish') {
    await page.waitForTimeout(1000);
    for (let attempt = 0; attempt < 3; attempt += 1) {
      const confirmed = await clickDialogButton(page, platform.confirmTexts ?? []);
      if (!confirmed) {
        break;
      }
      await page.waitForTimeout(900);
    }
  }

  const outcome = await waitForOutcome(page, platform, originalUrl, publishMode, 35000);
  const remote = extractRemoteReference(page.url(), platform);

  return {
    ok: true,
    status: outcome.status,
    remote_id: remote.id,
    remote_url: remote.url,
    remote_meta: {
      current_url: page.url(),
      publish_mode: publishMode,
      title_truncated: article.title !== String(request?.payload?.article?.title ?? '').trim(),
      evidence_source: outcome.source,
      evidence_text: outcome.text,
      remote_reference_source: remote.source ?? 'unavailable',
      content_verification: contentVerification,
      cover_verification: coverVerification,
    },
  };
}

export async function applyRequiredCover(page, platform, coverFile, timeoutMs = 20000) {
  if (!platform.requiresCover) {
    return null;
  }
  if (!coverFile) {
    throw new ManualActionError(`${platform.label}发布缺少可上传的封面图，系统已中止本次操作。`);
  }

  const config = platform.coverFlow;
  validateCoverFlowConfig(platform, config);

  const trigger = Array.isArray(config.triggerSelectors)
    ? await waitForUniqueVisibleSelector(
        page,
        config.triggerSelectors,
        Math.min(timeoutMs, 12000),
        '检测到多个可见的封面设置入口，无法安全判断目标，系统已中止本次操作。',
      )
    : await waitForUniqueVisibleText(page, config.triggerTexts, Math.min(timeoutMs, 12000));
  if (!trigger) {
    throw new ManualActionError(`${platform.label}未找到唯一的封面设置入口，页面结构可能已经变化，系统已中止本次操作。`);
  }
  await trigger.click();

  const usesInlineScope = Array.isArray(config.scopeSelectors);
  const scope = await waitForUniqueVisibleSelector(
    page,
    usesInlineScope ? config.scopeSelectors : config.dialogSelectors,
    Math.min(timeoutMs, 10000),
    usesInlineScope
      ? '检测到多个可见的封面上传区域，无法安全判断目标，系统已中止本次操作。'
      : '检测到多个可见的封面弹窗，无法安全判断目标，系统已中止本次操作。',
  );
  if (!scope) {
    throw new ManualActionError(`${platform.label}封面${usesInlineScope ? '上传区域' : '弹窗'}未出现，系统已中止本次操作。`);
  }

  const fileInput = await uniqueLocatorInScope(scope, config.fileInputSelectors, { allowHidden: true });
  if (!fileInput) {
    throw new ManualActionError(`${platform.label}封面${usesInlineScope ? '上传区域' : '弹窗'}内未找到唯一的图片上传控件，系统已中止本次操作。`);
  }
  await fileInput.setInputFiles(coverFile);
  const confirmButton = await waitForUniqueEnabledButtonInScope(
    page,
    scope,
    config.confirmTexts,
    Math.min(timeoutMs, 10000),
  );
  if (!confirmButton) {
    throw new ManualActionError(`${platform.label}封面上传后未出现唯一且可用的确认按钮，系统已中止本次操作。`);
  }
  await confirmButton.click();

  const scopeClosed = await waitUntilHidden(page, scope, Math.min(timeoutMs, 10000));
  if (!scopeClosed) {
    throw new ManualActionError(`${platform.label}封面${usesInlineScope ? '上传区域' : '弹窗'}未在确认后关闭，无法确认封面已生效，系统已中止本次操作。`);
  }

  return {
    required: true,
    file_name: coverFile.name,
    mime_type: coverFile.mimeType,
    upload_accepted: true,
    dialog_closed: true,
  };
}

export async function detectPageState(page, platform, timeoutMs = 5000) {
  const url = page.url().toLowerCase();
  const bodyText = await page.locator('body').innerText({ timeout: timeoutMs }).catch(() => '');
  const blockingText = findBlockingText(bodyText, platform);
  const loginHost = new URL(platform.loginUrl).hostname;
  const looksLikeLogin = url.includes('login') || url.includes('signin') || (url.includes(loginHost) && bodyText.includes('扫码登录'));

  return {
    url: page.url(),
    blockingText,
    looksLikeLogin,
  };
}

export async function inspectReadinessControls(page, platform) {
  const [titleEditor, bodyEditor, fileInputCount, coverSettingVisible, preUploadActionVisible] = await Promise.all([
    findVisibleOnce(page, platform.titleSelectors ?? []),
    findVisibleOnce(page, platform.editorSelectors ?? []),
    countAcrossFrames(page, 'input[type="file"]'),
    findVisibleTextOnce(page, ['设置封面', '选择封面']),
    findVisibleTextOnce(page, platform.preUploadActionTexts ?? []),
  ]);

  const controls = {
    title_editor_visible: Boolean(titleEditor),
    body_editor_visible: Boolean(bodyEditor),
    file_input_count: fileInputCount,
    cover_setting_visible: coverSettingVisible,
  };
  if (Array.isArray(platform.preUploadActionTexts) && platform.preUploadActionTexts.length > 0) {
    controls.pre_upload_action_visible = preUploadActionVisible;
  }

  return controls;
}

export async function waitForReadinessState(page, platform, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  let pageState;
  let controls;

  do {
    const remainingMs = Math.max(1, deadline - Date.now());
    [pageState, controls] = await Promise.all([
      detectPageState(page, platform, Math.min(1000, remainingMs)),
      inspectReadinessControls(page, platform),
    ]);

    if (
      pageState.blockingText
      || pageState.looksLikeLogin
      || readinessControlsAreUsable(platform, controls)
    ) {
      return { pageState, controls };
    }

    const waitMs = Math.min(300, deadline - Date.now());
    if (waitMs <= 0) {
      break;
    }
    await page.waitForTimeout(waitMs);
  } while (Date.now() < deadline);

  return { pageState, controls };
}

export function readinessControlsAreUsable(platform, controls) {
  if (Array.isArray(platform.preUploadActionTexts) && platform.preUploadActionTexts.length > 0) {
    return controls.pre_upload_action_visible === true;
  }

  return controls.title_editor_visible === true && controls.body_editor_visible === true;
}

async function throwIfPageBlocked(page, platform) {
  const state = await detectPageState(page, platform);
  if (state.blockingText !== '') {
    throw new ManualActionError(`平台当前显示“${state.blockingText}”，本次无法发布，请处理后重试。`, state);
  }
  if (state.looksLikeLogin) {
    throw new ManualActionError('账号尚未登录或登录已失效，请先点击“打开登录窗口”。', state);
  }
}

async function throwPageStateError(page, platform, fallback = '') {
  await throwIfPageBlocked(page, platform);
  const state = await detectPageState(page, platform);
  throw new ManualActionError(fallback || '未找到文章标题输入框，请检查账号登录状态或平台页面变化。', state);
}

export async function prepareRequiredImageUpload(page, platform, files, timeoutMs) {
  if (Array.isArray(platform.preUploadActionTexts) && platform.preUploadActionTexts.length > 0) {
    const action = await waitForUniqueVisualTextTarget(
      page,
      platform.preUploadActionTexts,
      Math.min(timeoutMs, 12000),
    );
    if (!action) {
      throw new ManualActionError(`未找到${platform.label}的“${platform.preUploadActionTexts[0]}”入口，系统已中止本次操作。`);
    }
    await action.click();
    await page.waitForTimeout(500);
  }

  const selectors = Array.isArray(platform.imageUploadSelectors) && platform.imageUploadSelectors.length > 0
    ? platform.imageUploadSelectors
    : ['input[type="file"]'];
  const input = await findVisible(page, selectors, Math.min(timeoutMs, 20000), true);
  if (!input) {
    throw new ManualActionError('未找到图片上传控件，请在浏览器中检查小红书发布页。');
  }
  await input.setInputFiles(files);
}

export async function clickPublishControl(page, platform, texts, timeoutMs) {
  if (platform.publishControlMode !== 'exact_text') {
    return clickButtonByTexts(page, texts, timeoutMs);
  }

  const control = await waitForUniqueVisualTextTarget(page, texts, timeoutMs);
  if (!control) {
    return false;
  }
  const disabled = await control.evaluate((node) => Boolean(
    node.closest('[disabled], [aria-disabled="true"], .disabled, [class*="disabled"]')
  )).catch(() => false);
  if (disabled) {
    throw new ManualActionError(`${platform.label}发布控件当前不可用，系统已中止本次操作。`);
  }
  await control.click();

  return true;
}

async function waitForUniqueVisualTextTarget(page, texts, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const matches = [];
    for (const context of frameContexts(page)) {
      for (const text of texts) {
        const candidates = context.getByText(text, { exact: true });
        const count = await candidates.count().catch(() => 0);
        for (let index = 0; index < count; index += 1) {
          const candidate = candidates.nth(index);
          if (await candidate.isVisible().catch(() => false)) {
            matches.push(candidate);
          }
        }
      }
    }

    const visualTargets = await collapseOverlappingTextTargets(matches);
    if (visualTargets.length > 1) {
      throw new ManualActionError(
        '检测到多个不同位置的图文上传入口，无法安全判断目标，系统已中止本次操作。',
        { candidates: await describeTextTargets(visualTargets) },
      );
    }
    if (visualTargets.length === 1) {
      return visualTargets[0];
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return null;
}

async function describeTextTargets(matches) {
  return Promise.all(matches.map(async (candidate) => {
    const [box, element] = await Promise.all([
      candidate.boundingBox().catch(() => null),
      candidate.evaluate((node) => ({
        tag: node.tagName.toLowerCase(),
        role: node.getAttribute('role'),
        class_name: typeof node.className === 'string' ? node.className.slice(0, 300) : '',
        parent_tag: node.parentElement?.tagName.toLowerCase() ?? null,
        parent_class_name: typeof node.parentElement?.className === 'string'
          ? node.parentElement.className.slice(0, 300)
          : '',
      })).catch(() => null),
    ]);

    return { box, element };
  }));
}

async function collapseOverlappingTextTargets(matches) {
  if (matches.length <= 1) {
    return matches;
  }

  const measured = [];
  for (const candidate of matches) {
    const box = await candidate.boundingBox().catch(() => null);
    if (!box || box.width <= 0 || box.height <= 0) {
      return matches;
    }
    if (box.x + box.width <= 0 || box.y + box.height <= 0) {
      continue;
    }
    measured.push({ candidate, box, area: box.width * box.height });
  }

  const remaining = [...measured].sort((left, right) => left.area - right.area);
  const groups = [];
  while (remaining.length > 0) {
    const target = remaining.shift();
    const group = [target];
    for (let index = remaining.length - 1; index >= 0; index -= 1) {
      if (boxesRepresentSameVisualTarget(target.box, remaining[index].box)) {
        group.push(remaining[index]);
        remaining.splice(index, 1);
      }
    }
    groups.push(group);
  }

  return groups.map((group) => group.sort((left, right) => left.area - right.area)[0].candidate);
}

function boxesRepresentSameVisualTarget(left, right) {
  const intersectionWidth = Math.max(0, Math.min(left.x + left.width, right.x + right.width) - Math.max(left.x, right.x));
  const intersectionHeight = Math.max(0, Math.min(left.y + left.height, right.y + right.height) - Math.max(left.y, right.y));
  const intersectionArea = intersectionWidth * intersectionHeight;
  const smallerArea = Math.min(left.width * left.height, right.width * right.height);

  return smallerArea > 0 && intersectionArea / smallerArea >= 0.65;
}

async function fillEditor(page, locator, article, format, usePlainText) {
  const value = format === 'markdown' ? article.markdown || article.plain : usePlainText ? article.plain : article.html;
  const htmlValue = usePlainText
    ? article.plain.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')
    : article.html;
  const tagName = await locator.evaluate((element) => element.tagName.toLowerCase());
  if (tagName === 'textarea' || tagName === 'input') {
    await locator.fill(value);
    return;
  }

  await locator.click();
  if (format === 'markdown') {
    await locator.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
    await page.keyboard.insertText(value);
    return;
  }

  const modifier = process.platform === 'darwin' ? 'Meta' : 'Control';
  await locator.press(`${modifier}+A`).catch(() => {});
  await locator.press('Backspace').catch(() => {});
  const pasted = await writeRichClipboard(page, htmlValue, article.plain);
  if (pasted) {
    await page.keyboard.press(`${modifier}+V`);
    await page.waitForTimeout(300);
    const insertedText = await locator.innerText().catch(() => '');
    if (insertedText.trim() !== '') {
      return;
    }
  }

  await locator.evaluate((element, html) => {
    element.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(element);
    selection?.removeAllRanges();
    selection?.addRange(range);
    const inserted = document.execCommand('insertHTML', false, html);
    if (inserted) {
      return;
    }
    element.innerHTML = html;
    element.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, inputType: 'insertFromPaste', data: null }));
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste', data: null }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }, htmlValue);
}

async function verifyInsertedContent(page, titleInput, editor, article, platform) {
  const expectedBody = platform.editorFormat === 'markdown'
    ? article.markdown || article.plain
    : platform.requiresImage
      ? article.plain
      : htmlToPlainText(article.html);
  const deadline = Date.now() + 3000;
  let verification;

  do {
    const [actualTitle, actualBody] = await Promise.all([
      readEditableText(titleInput),
      readEditableText(editor),
    ]);
    const title = assessTextIntegrity(article.title, actualTitle, {
      minLengthRatio: 1,
      maxLengthRatio: 1,
      anchorLength: 32,
    });
    const body = assessTextIntegrity(expectedBody, actualBody);
    verification = { title, body };
    if (title.ok && body.ok) {
      return verification;
    }
    await page.waitForTimeout(200);
  } while (Date.now() < deadline);

  const failedParts = [!verification.title.ok ? '标题' : '', !verification.body.ok ? '正文' : ''].filter(Boolean).join('和');
  throw new ManualActionError(`发布前${failedParts}完整性校验失败，系统已中止本次操作，请检查平台编辑器是否变化。`, {
    content_verification: verification,
  });
}

async function readEditableText(locator) {
  return locator.evaluate((element) => {
    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
      return element.value;
    }
    return element.innerText || element.textContent || '';
  }).catch(() => '');
}

function normalizeComparableText(value) {
  return String(value ?? '')
    .normalize('NFKC')
    .replace(/[\s\u200B-\u200D\u2060\uFEFF•●◦]/gu, '');
}

async function writeRichClipboard(page, html, plain) {
  try {
    const origin = new URL(page.url()).origin;
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin });
    return await page.evaluate(async ({ clipboardHtml, clipboardPlain }) => {
      if (!navigator.clipboard || typeof ClipboardItem === 'undefined') {
        return false;
      }
      await navigator.clipboard.write([new ClipboardItem({
        'text/html': new Blob([clipboardHtml], { type: 'text/html' }),
        'text/plain': new Blob([clipboardPlain], { type: 'text/plain' }),
      })]);
      return true;
    }, { clipboardHtml: html, clipboardPlain: plain });
  } catch {
    return false;
  }
}

async function findVisible(page, selectors, timeoutMs, allowHiddenFileInput = false) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const candidate = await findVisibleOnce(page, selectors, allowHiddenFileInput);
    if (candidate) {
      return candidate;
    }
    await page.waitForTimeout(300);
  }
  return null;
}

function validateCoverFlowConfig(platform, config) {
  const requiredArrays = ['fileInputSelectors', 'confirmTexts'];
  const invalid = requiredArrays.filter((key) => !Array.isArray(config?.[key]) || config[key].length === 0);
  if (
    (!Array.isArray(config?.triggerTexts) || config.triggerTexts.length === 0)
    && (!Array.isArray(config?.triggerSelectors) || config.triggerSelectors.length === 0)
  ) {
    invalid.push('triggerTexts|triggerSelectors');
  }
  if (
    (!Array.isArray(config?.dialogSelectors) || config.dialogSelectors.length === 0)
    && (!Array.isArray(config?.scopeSelectors) || config.scopeSelectors.length === 0)
  ) {
    invalid.push('dialogSelectors|scopeSelectors');
  }
  if (invalid.length > 0) {
    throw new ManualActionError(`${platform.label}封面自动化配置不完整，系统已中止本次操作。`, {
      missing_cover_config: invalid,
    });
  }
}

async function waitForUniqueVisibleText(page, texts, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const matches = [];
    for (const context of frameContexts(page)) {
      for (const text of texts) {
        const candidates = context.getByText(text, { exact: true });
        const count = await candidates.count().catch(() => 0);
        for (let index = 0; index < count; index += 1) {
          const candidate = candidates.nth(index);
          if (await candidate.isVisible().catch(() => false)) {
            matches.push(candidate);
          }
        }
      }
    }
    if (matches.length > 1) {
      throw new ManualActionError('检测到多个可见的封面设置入口，无法安全判断目标，系统已中止本次操作。');
    }
    if (matches.length === 1) {
      return matches[0];
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return null;
}

async function waitForUniqueVisibleSelector(page, selectors, timeoutMs, ambiguousMessage = '') {
  const deadline = Date.now() + timeoutMs;
  const combinedSelector = selectors.join(', ');
  while (Date.now() < deadline) {
    const matches = [];
    for (const context of frameContexts(page)) {
      const candidates = context.locator(combinedSelector);
      const count = await candidates.count().catch(() => 0);
      for (let index = 0; index < count; index += 1) {
        const candidate = candidates.nth(index);
        if (await candidate.isVisible().catch(() => false)) {
          matches.push(candidate);
        }
      }
    }
    if (matches.length > 1) {
      throw new ManualActionError(ambiguousMessage || '检测到多个可见的封面弹窗，无法安全判断目标，系统已中止本次操作。');
    }
    if (matches.length === 1) {
      return matches[0];
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return null;
}

async function uniqueLocatorInScope(scope, selectors, { allowHidden = false } = {}) {
  const candidates = scope.locator(selectors.join(', '));
  const count = await candidates.count().catch(() => 0);
  const matches = [];
  for (let index = 0; index < count; index += 1) {
    const candidate = candidates.nth(index);
    if (allowHidden || await candidate.isVisible().catch(() => false)) {
      matches.push(candidate);
    }
  }
  if (matches.length > 1) {
    throw new ManualActionError('封面弹窗内存在多个候选图片控件，无法安全判断目标，系统已中止本次操作。');
  }
  return matches[0] ?? null;
}

async function uniqueEnabledButtonInScope(scope, texts) {
  const matches = [];
  for (const text of texts) {
    const candidates = scope.getByRole('button', { name: text, exact: false });
    const count = await candidates.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const candidate = candidates.nth(index);
      if (
        await candidate.isVisible().catch(() => false)
        && await candidate.isEnabled().catch(() => false)
      ) {
        matches.push(candidate);
      }
    }
  }
  if (matches.length > 1) {
    throw new ManualActionError('封面弹窗内存在多个可用确认按钮，无法安全判断目标，系统已中止本次操作。');
  }
  return matches[0] ?? null;
}

async function waitForUniqueEnabledButtonInScope(page, scope, texts, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const button = await uniqueEnabledButtonInScope(scope, texts);
    if (button) {
      return button;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return null;
}

async function waitUntilHidden(page, locator, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (!await locator.isVisible().catch(() => false)) {
      return true;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return !await locator.isVisible().catch(() => false);
}

function frameContexts(page) {
  const frames = page.frames();
  return frames.length > 0 ? frames : [page];
}

async function findVisibleOnce(page, selectors, allowHiddenFileInput = false) {
  for (const context of [page, ...page.frames()]) {
    for (const selector of selectors) {
      const candidates = context.locator(selector);
      const count = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = 0; index < count; index += 1) {
        const candidate = candidates.nth(index);
        if (allowHiddenFileInput || await candidate.isVisible().catch(() => false)) {
          return candidate;
        }
      }
    }
  }
  return null;
}

async function countAcrossFrames(page, selector) {
  const frames = page.frames();
  const contexts = frames.length > 0 ? frames : [page];
  const counts = await Promise.all(contexts.map((context) => context.locator(selector).count().catch(() => 0)));
  return counts.reduce((total, count) => total + count, 0);
}

async function findVisibleTextOnce(page, texts) {
  const frames = page.frames();
  const contexts = frames.length > 0 ? frames : [page];
  for (const context of contexts) {
    for (const text of texts) {
      const candidates = context.getByText(text, { exact: false });
      const count = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = 0; index < count; index += 1) {
        if (await candidates.nth(index).isVisible().catch(() => false)) {
          return true;
        }
      }
    }
  }
  return false;
}

async function clickButtonByTexts(page, texts, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    for (const text of texts) {
      const candidates = page.getByRole('button', { name: text, exact: false });
      const count = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = count - 1; index >= 0; index -= 1) {
        const candidate = candidates.nth(index);
        if (await candidate.isVisible().catch(() => false) && await candidate.isEnabled().catch(() => false)) {
          await candidate.click();
          return true;
        }
      }
    }
    await page.waitForTimeout(300);
  }
  return false;
}

async function clickDialogButton(page, texts) {
  const dialogs = page.locator('[role="dialog"], .ant-modal, .semi-modal, .el-dialog');
  const count = Math.min(await dialogs.count().catch(() => 0), 5);
  for (let dialogIndex = count - 1; dialogIndex >= 0; dialogIndex -= 1) {
    const dialog = dialogs.nth(dialogIndex);
    if (!await dialog.isVisible().catch(() => false)) {
      continue;
    }
    for (const text of texts) {
      const buttons = dialog.getByRole('button', { name: text, exact: false });
      const buttonCount = await buttons.count().catch(() => 0);
      for (let index = buttonCount - 1; index >= 0; index -= 1) {
        const button = buttons.nth(index);
        if (await button.isVisible().catch(() => false) && await button.isEnabled().catch(() => false)) {
          await button.click();
          return true;
        }
      }
    }
  }
  return false;
}

async function clickOptionalText(page, text) {
  const candidate = page.getByText(text, { exact: true }).last();
  if (await candidate.isVisible().catch(() => false)) {
    await candidate.click().catch(() => {});
  }
}

async function waitForOutcome(page, platform, originalUrl, publishMode, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const state = await detectPageState(page, platform);
    if (state.blockingText !== '') {
      throw new ManualActionError(`平台当前显示“${state.blockingText}”，本次发布无法确认成功，请处理后重试。`, state);
    }
    const currentUrl = page.url();
    const platformUrlOutcome = classifyPlatformSuccessUrlEvidence(currentUrl, platform, publishMode);
    if (currentUrl !== originalUrl && platformUrlOutcome) {
      return platformUrlOutcome;
    }
    const bodyText = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');
    const textOutcome = classifyOutcomeEvidence(bodyText, platform, publishMode);
    if (textOutcome) {
      return textOutcome;
    }
    const remote = extractRemoteReference(currentUrl, platform);
    if (currentUrl !== originalUrl && remote.url !== null) {
      return {
        status: publishMode === 'draft' ? 'draft' : 'published',
        source: 'public_url_pattern',
        text: null,
      };
    }
    await page.waitForTimeout(600);
  }
  throw new ManualActionError('平台未返回可确认的发布成功状态，请在浏览器中核对，系统不会把本次任务标记为成功。', { url: page.url() });
}
