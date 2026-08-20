import { ManualActionError, RunnerError } from './errors.js';
import { articleFromRequest, coverFileFromRequest, htmlToPlainText, uploadFilesFromRequest } from './content.js';
import { applyAiDisclosure } from './ai-disclosure.js';

const blockingTexts = ['安全验证', '异常登录', '账号存在风险', '请完成验证', '滑块验证', '扫码登录'];

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

export function plainSourceNamesApprovalFromRequest(request, platformKey) {
  const approval = request?.plain_source_names_approval;
  if (approval == null) {
    return null;
  }
  if (
    typeof approval !== 'object'
    || Array.isArray(approval)
    || approval.approved !== true
    || approval.platform !== 'sohu'
    || !Number.isInteger(approval.article_distribution_id)
    || approval.article_distribution_id <= 0
    || !approvalText(approval.approved_by, 255)
    || !approvalText(approval.approved_at, 255)
    || typeof approval.payload_hash !== 'string'
    || !/^[a-f0-9]{64}$/.test(approval.payload_hash)
  ) {
    throw invalidPlainSourceNamesApproval();
  }
  if (platformKey !== 'sohu') {
    return null;
  }

  return {
    approved: true,
    platform: 'sohu',
    articleDistributionId: approval.article_distribution_id,
    approvedBy: approval.approved_by.trim(),
    approvedAt: approval.approved_at.trim(),
    payloadHash: approval.payload_hash,
  };
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

export async function publishWithBrowser(page, platformKey, platform, request, timeoutMs, options = {}) {
  if (!['publish', 'draft', 'simulate'].includes(request?.publish_mode)) {
    throw new RunnerError('publish_mode 必须是 publish、draft 或 simulate。', {
      code: 'invalid_payload',
      status: 422,
    });
  }

  const plainSourceNamesApproval = plainSourceNamesApprovalFromRequest(request, platformKey);
  const article = articleFromRequest(request, platform);
  const files = uploadFilesFromRequest(request, platform);
  const publishMode = request.publish_mode;
  const coverFile = publishMode !== 'draft' ? coverFileFromRequest(request, platform) : null;
  const originalUrl = page.url();

  const navigationRecovery = await navigateToPublishingEditor(page, platform, timeoutMs);
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

  const editor = await findVisible(page, platform.editorSelectors, 15000);
  if (!editor) {
    await throwPageStateError(page, platform, '未找到正文编辑器，平台页面结构可能已经变化。');
  }
  await dismissEditorOverlays(page, platform, Math.min(timeoutMs, 3000));
  await titleInput.fill(article.title);
  await fillEditor(page, editor, article, platform.editorFormat, platform.requiresImage, platform.richInsertMode);
  const contentVerification = await verifyInsertedContent(
    page,
    titleInput,
    editor,
    article,
    platform,
    plainSourceNamesApproval,
  );

  const coverVerification = coverFile
    ? await applyRequiredCover(page, platform, coverFile, Math.min(timeoutMs, 20000))
    : null;
  const isAiGenerated = request?.payload?.article?.is_ai_generated === true
    || request?.payload?.article?.is_ai_generated === 1;
  const aiDisclosureVerification = isAiGenerated
    ? await applyAiDisclosure(page, platformKey, platform.aiDisclosure)
    : null;

  for (const text of platform.optionalActions ?? []) {
    await clickOptionalText(page, text);
  }
  const requiredUncheckedOptionsVerification = platform.requiredUncheckedOptions
    ? await applyRequiredUncheckedOptions(
        page,
        platformKey,
        platform.requiredUncheckedOptions,
        Math.min(timeoutMs, 10000),
      )
    : null;

  if (publishMode === 'simulate') {
    return {
      ok: true,
      status: 'simulated',
      remote_id: null,
      remote_url: null,
      remote_meta: {
        current_url: page.url(),
        publish_mode: publishMode,
        ...(navigationRecovery ? { navigation_recovery: navigationRecovery } : {}),
        ...(aiDisclosureVerification ? { ai_disclosure_verification: aiDisclosureVerification } : {}),
        ...(requiredUncheckedOptionsVerification
          ? { required_unchecked_options_verification: requiredUncheckedOptionsVerification }
          : {}),
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
  const clicked = await clickPublishControl(
    page,
    platform,
    publishTexts,
    12000,
    options.onSubmitAttempt,
  );
  if (!clicked) {
    throw new ManualActionError(`未找到${publishMode === 'draft' ? '保存草稿' : '发布'}按钮，请在已打开的浏览器中检查页面。`);
  }

  if (publishMode === 'publish') {
    await page.waitForTimeout(1000);
    await clickConfirmationControl(
      page,
      platform,
      platform.confirmControlMode === 'exact_text' ? 12000 : 3000,
    );
  }

  const outcome = await waitForOutcome(page, platform, originalUrl, publishMode, Math.min(timeoutMs, 35000));
  const remote = extractRemoteReference(page.url(), platform);

  return {
    ok: true,
    status: outcome.status,
    remote_id: remote.id,
    remote_url: remote.url,
    remote_meta: {
      current_url: page.url(),
      publish_mode: publishMode,
      ...(navigationRecovery ? { navigation_recovery: navigationRecovery } : {}),
      ...(aiDisclosureVerification ? { ai_disclosure_verification: aiDisclosureVerification } : {}),
      ...(requiredUncheckedOptionsVerification
        ? { required_unchecked_options_verification: requiredUncheckedOptionsVerification }
        : {}),
      title_truncated: article.title !== String(request?.payload?.article?.title ?? '').trim(),
      evidence_source: outcome.source,
      evidence_text: outcome.text,
      remote_reference_source: remote.source ?? 'unavailable',
      content_verification: contentVerification,
      cover_verification: coverVerification,
    },
  };
}

export async function applyRequiredUncheckedOptions(
  page,
  platformKey,
  options,
  timeoutMs = 10000,
) {
  if (!Array.isArray(options) || options.length === 0) {
    throw new ManualActionError(`${platformKey} 的必关选项配置不完整，系统已中止本次操作。`);
  }

  const verification = [];
  for (const option of options) {
    const text = typeof option?.text === 'string' ? option.text.trim() : '';
    const checkedSelectors = validSelectors(option?.checkedEvidenceSelectors);
    const uncheckedSelectors = validSelectors(option?.uncheckedEvidenceSelectors);
    if (text === '' || checkedSelectors.length === 0 || uncheckedSelectors.length === 0) {
      throw new ManualActionError(`${platformKey} 的必关选项配置不完整，系统已中止本次操作。`);
    }

    const control = await waitForUniqueVisualTextTarget(
      page,
      [text],
      timeoutMs,
      `${text}开关`,
    );
    if (!control) {
      throw new ManualActionError(`${platformKey} 的${text}开关无法安全操作，系统已中止本次操作。`);
    }
    const initialState = await waitForRequiredOptionState(
      page,
      text,
      checkedSelectors,
      uncheckedSelectors,
      control,
      timeoutMs,
    );
    let changed = false;
    let verifiedState = initialState;
    if (initialState.state !== 'unchecked') {
      try {
        await control.click();
      } catch {
        throw new ManualActionError(`${platformKey} 的${text}开关无法安全操作，系统已中止本次操作。`);
      }
      changed = true;
      verifiedState = await waitForRequiredOptionUnchecked(
        page,
        platformKey,
        text,
        checkedSelectors,
        uncheckedSelectors,
        control,
        timeoutMs,
      );
    }

    verification.push({
      text,
      unchecked: true,
      changed,
      evidence: verifiedState.evidence,
    });
  }

  return {
    required: true,
    platform: platformKey,
    all_unchecked: true,
    options: verification,
  };
}

async function waitForRequiredOptionState(
  page,
  text,
  checkedSelectors,
  uncheckedSelectors,
  control,
  timeoutMs,
) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const state = await readRequiredOptionState(page, text, checkedSelectors, uncheckedSelectors, control);
    if (state.state !== 'unknown') {
      return state;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }

  throw new ManualActionError(`无法确认${text}当前是否关闭，系统已中止本次操作。`);
}

async function waitForRequiredOptionUnchecked(
  page,
  platformKey,
  text,
  checkedSelectors,
  uncheckedSelectors,
  control,
  timeoutMs,
) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const state = await readRequiredOptionState(page, text, checkedSelectors, uncheckedSelectors, control);
    if (state.state === 'unchecked') {
      return state;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }

  throw new ManualActionError(`${platformKey} 的${text}无法确认已关闭，系统已中止本次操作。`);
}

async function readRequiredOptionState(page, text, checkedSelectors, uncheckedSelectors, control) {
  const checkedCount = await visibleSelectorCount(page, checkedSelectors);
  const uncheckedCount = await visibleSelectorCount(page, uncheckedSelectors);
  if (checkedCount > 1 || uncheckedCount > 1 || (checkedCount > 0 && uncheckedCount > 0)) {
    throw new ManualActionError(`检测到${text}的开关状态证据不唯一，系统已中止本次操作。`);
  }
  if (uncheckedCount === 1) {
    return {
      state: 'unchecked',
      evidence: {
        attribute: 'selector_state',
        value: uncheckedSelectors.join(', '),
      },
    };
  }
  if (checkedCount === 1) {
    return {
      state: 'checked',
      evidence: {
        attribute: 'selector_state',
        value: checkedSelectors.join(', '),
      },
    };
  }

  const nativeState = await readNativeRequiredOptionState(control);
  if (nativeState?.state === 'ambiguous') {
    throw new ManualActionError(`检测到${text}的开关状态证据不唯一，系统已中止本次操作。`);
  }

  return nativeState ?? { state: 'unknown', evidence: null };
}

async function readNativeRequiredOptionState(control) {
  if (typeof control?.evaluate !== 'function') {
    return null;
  }

  return control.evaluate((node) => {
    const scopes = [];
    const seenScopes = new Set();
    const addScope = (scope) => {
      if (!scope || seenScopes.has(scope)) {
        return;
      }
      seenScopes.add(scope);
      scopes.push(scope);
    };
    const candidatesWithin = (scope, selector) => {
      const candidates = [];
      const seen = new Set();
      const add = (candidate) => {
        if (!candidate || seen.has(candidate)) {
          return;
        }
        seen.add(candidate);
        candidates.push(candidate);
      };
      if (scope.matches?.(selector)) {
        add(scope);
      }
      for (const candidate of scope.querySelectorAll?.(selector) ?? []) {
        add(candidate);
      }
      return candidates;
    };
    const stateFrom = (candidate) => {
      if (typeof candidate?.checked === 'boolean') {
        return {
          state: candidate.checked ? 'checked' : 'unchecked',
          evidence: { attribute: 'checked', value: candidate.checked },
        };
      }

      const ariaChecked = candidate?.getAttribute?.('aria-checked')?.toLowerCase();
      if (ariaChecked === 'true' || ariaChecked === 'false') {
        return {
          state: ariaChecked === 'true' ? 'checked' : 'unchecked',
          evidence: { attribute: 'aria-checked', value: ariaChecked },
        };
      }

      const dataState = candidate?.getAttribute?.('data-state')?.toLowerCase();
      if (['checked', 'on', 'true', 'unchecked', 'off', 'false'].includes(dataState)) {
        return {
          state: ['checked', 'on', 'true'].includes(dataState) ? 'checked' : 'unchecked',
          evidence: { attribute: 'data-state', value: dataState },
        };
      }

      if (candidate?.classList?.contains('one-checkbox')) {
        const checked = candidate.classList.contains('one-checkbox-checked');
        return {
          state: checked ? 'checked' : 'unchecked',
          evidence: { attribute: 'class:one-checkbox-checked', value: checked },
        };
      }

      return null;
    };
    const uniqueStateWithin = (scope) => {
      for (const selector of [
        'input[type="checkbox"]',
        '[role="checkbox"], [role="switch"]',
        '.one-checkbox',
      ]) {
        const candidates = candidatesWithin(scope, selector);
        if (candidates.length > 1) {
          return { state: 'ambiguous', evidence: null };
        }
        if (candidates.length === 1) {
          return stateFrom(candidates[0]);
        }
      }
      return null;
    };

    addScope(node);
    addScope(node.closest?.('label'));
    addScope(node.closest?.('[role="checkbox"], [role="switch"]'));
    addScope(node.closest?.('.one-checkbox-wrapper'));
    let ancestor = node.parentElement;
    for (let depth = 0; depth < 4 && ancestor; depth += 1) {
      addScope(ancestor);
      ancestor = ancestor.parentElement;
    }

    for (const scope of scopes) {
      const state = uniqueStateWithin(scope);
      if (state) {
        return state;
      }
    }
    return null;
  }).catch(() => null);
}

async function visibleSelectorCount(page, selectors) {
  let count = 0;
  for (const context of frameContexts(page)) {
    for (const selector of selectors) {
      const candidates = context.locator(selector);
      const candidateCount = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = 0; index < candidateCount; index += 1) {
        if (await candidates.nth(index).isVisible().catch(() => false)) {
          count += 1;
        }
      }
    }
  }
  return count;
}

function validSelectors(selectors) {
  return Array.isArray(selectors)
    ? selectors.filter((selector) => typeof selector === 'string' && selector.trim() !== '')
    : [];
}

async function navigateToPublishingEditor(page, platform, timeoutMs) {
  try {
    await page.goto(platform.publishUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    return null;
  } catch (error) {
    if (!editorLocationMatches(page.url(), platform.publishUrl)) {
      throw error;
    }

    const { pageState, controls } = await waitForReadinessState(
      page,
      platform,
      Math.min(timeoutMs, 5000),
    );
    if (pageState.blockingText !== ''
      || pageState.looksLikeLogin
      || !readinessControlsAreUsable(platform, controls)) {
      throw error;
    }

    return {
      recovered: true,
      issue: error?.name === 'TimeoutError' ? 'timeout' : 'failed',
      current_url: pageState.url,
    };
  }
}

export function editorLocationMatches(currentUrl, targetUrl) {
  try {
    const current = new URL(currentUrl);
    const target = new URL(targetUrl);
    if (current.origin !== target.origin || current.pathname !== target.pathname) {
      return false;
    }
    return current.search === target.search && current.hash === target.hash;
  } catch {
    return false;
  }
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

  const confirmationDeadline = Date.now() + Math.min(timeoutMs, 10000);
  const maxConfirmAttempts = Math.min(3, Math.max(1, Number(config.maxConfirmAttempts ?? 1)));
  let confirmAttempts = 0;
  let scopeClosed = false;
  while (confirmAttempts < maxConfirmAttempts && Date.now() < confirmationDeadline) {
    const remainingMs = Math.max(1, confirmationDeadline - Date.now());
    const confirmButton = await waitForUniqueEnabledButtonInScope(
      page,
      scope,
      config.confirmTexts,
      remainingMs,
    );
    if (!confirmButton) {
      throw new ManualActionError(`${platform.label}封面上传后未出现唯一且可用的确认按钮，系统已中止本次操作。`);
    }
    await confirmButton.click();
    confirmAttempts += 1;

    const outcome = await waitForCoverConfirmationOutcome(
      page,
      scope,
      config.retryableConfirmTexts,
      Math.max(1, confirmationDeadline - Date.now()),
    );
    if (outcome.closed) {
      scopeClosed = true;
      break;
    }
    if (!outcome.retryNotice || confirmAttempts >= maxConfirmAttempts) {
      break;
    }

    const noticeHidden = await waitUntilHidden(
      page,
      outcome.retryNotice,
      Math.max(1, confirmationDeadline - Date.now()),
    );
    if (!noticeHidden || Date.now() >= confirmationDeadline) {
      break;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, confirmationDeadline - Date.now())));
  }

  if (!scopeClosed) {
    throw new ManualActionError(`${platform.label}封面${usesInlineScope ? '上传区域' : '弹窗'}未在确认后关闭，无法确认封面已生效，系统已中止本次操作。`);
  }

  return {
    required: true,
    file_name: coverFile.name,
    mime_type: coverFile.mimeType,
    upload_accepted: true,
    dialog_closed: true,
    ...(confirmAttempts > 1 ? { confirm_attempts: confirmAttempts } : {}),
  };
}

export async function detectPageState(page, platform, timeoutMs = 5000) {
  const url = page.url().toLowerCase();
  const surfaceText = await readSurfaceText(page, [
    ...(platform.titleSelectors ?? []),
    ...(platform.editorSelectors ?? []),
  ], timeoutMs);
  const blockingText = findBlockingText(surfaceText, platform);
  const loginHost = new URL(platform.loginUrl).hostname;
  const looksLikeLogin = url.includes('login') || url.includes('signin') || (url.includes(loginHost) && surfaceText.includes('扫码登录'));

  return {
    url: page.url(),
    blockingText,
    looksLikeLogin,
    surfaceText,
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

    if (pageState.blockingText !== '' && pageState.blockingText !== '安全验证') {
      return { pageState, controls };
    }
    if (readinessControlsAreUsable(platform, controls)) {
      return {
        pageState: pageState.blockingText === '安全验证'
          ? { ...pageState, blockingText: '' }
          : pageState,
        controls,
      };
    }
    if (pageState.looksLikeLogin) {
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
    const [titleEditor, bodyEditor] = state.blockingText === '安全验证'
      ? await Promise.all([
          findVisibleOnce(page, platform.titleSelectors ?? []),
          findVisibleOnce(page, platform.editorSelectors ?? []),
        ])
      : [null, null];
    if (!titleEditor || !bodyEditor) {
      throw new ManualActionError(
        `平台当前显示“${state.blockingText}”，本次无法发布，请处理后重试。`,
        pageStateDetails(state),
      );
    }
  }
  if (state.looksLikeLogin) {
    throw new ManualActionError('账号尚未登录或登录已失效，请先点击“打开登录窗口”。', pageStateDetails(state));
  }
}

async function throwPageStateError(page, platform, fallback = '') {
  await throwIfPageBlocked(page, platform);
  const state = await detectPageState(page, platform);
  throw new ManualActionError(
    fallback || '未找到文章标题输入框，请检查账号登录状态或平台页面变化。',
    pageStateDetails(state),
  );
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

export async function clickPublishControl(page, platform, texts, timeoutMs, onBeforeClick) {
  if (platform.publishControlMode !== 'exact_text') {
    return clickButtonByTexts(page, texts, timeoutMs, onBeforeClick);
  }

  const control = await waitForUniqueVisualTextTarget(page, texts, timeoutMs, '发布控件');
  if (!control) {
    return false;
  }
  const disabled = await control.evaluate((node) => Boolean(
    node.closest('[disabled], [aria-disabled="true"], .disabled, [class*="disabled"]')
  )).catch(() => false);
  if (disabled) {
    throw new ManualActionError(`${platform.label}发布控件当前不可用，系统已中止本次操作。`);
  }
  await onBeforeClick?.();
  await control.click();

  return true;
}

export async function clickConfirmationControl(page, platform, timeoutMs = 3000) {
  const texts = platform.confirmTexts ?? [];
  if (platform.confirmControlMode !== 'exact_text') {
    return clickDialogButton(page, texts);
  }

  const control = await waitForUniqueVisualTextTarget(page, texts, timeoutMs, '确认发布控件');
  if (!control) {
    return false;
  }
  const disabled = await control.evaluate((node) => Boolean(
    node.closest('[disabled], [aria-disabled="true"], .disabled, [class*="disabled"]')
  )).catch(() => false);
  if (disabled) {
    throw new ManualActionError(`${platform.label}确认发布控件当前不可用，系统已中止本次操作。`);
  }
  await control.click();

  return true;
}

async function waitForUniqueVisualTextTarget(page, texts, timeoutMs, ambiguityLabel = '图文上传入口') {
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
        `检测到多个不同位置的${ambiguityLabel}，无法安全判断目标，系统已中止本次操作。`,
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

async function fillEditor(page, locator, article, format, usePlainText, richInsertMode) {
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
  const pasted = richInsertMode === 'dom'
    ? false
    : await writeRichClipboard(page, htmlValue, article.plain);
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

async function verifyInsertedContent(page, titleInput, editor, article, platform, plainSourceNamesApproval = null) {
  const expectedBody = platform.editorFormat === 'markdown'
    ? article.markdown || article.plain
    : platform.requiresImage
      ? article.plain
      : htmlToPlainText(article.html);
  const expectedLinks = platform.editorFormat === 'markdown' || platform.requiresImage
    ? []
    : extractHttpLinks(article.html);
  const expectedLinkReferences = plainSourceNamesApproval
    ? extractHttpLinkReferences(article.html)
    : [];
  const deadline = Date.now() + 3000;
  let verification;

  do {
    const [actualTitle, actualBody, actualLinks] = await Promise.all([
      readEditableText(titleInput),
      readEditableText(editor),
      readEditableLinks(editor),
    ]);
    const title = assessTextIntegrity(article.title, actualTitle, {
      minLengthRatio: 1,
      maxLengthRatio: 1,
      anchorLength: 32,
    });
    const body = assessTextIntegrity(expectedBody, actualBody);
    let links = assessLinkIntegrity(expectedLinks, actualLinks);
    if (!links.ok && plainSourceNamesApproval) {
      const missingReferences = expectedLinkReferences.filter((reference) => links.missingUrls.includes(reference.url));
      const sourceNames = assessSourceNameIntegrity(missingReferences, actualBody);
      links = {
        ...links,
        plain_source_names: {
          ok: sourceNames.ok,
          platform: 'sohu',
          article_distribution_id: plainSourceNamesApproval.articleDistributionId,
          payload_hash: plainSourceNamesApproval.payloadHash,
          expectedNames: sourceNames.expected,
          actualNames: sourceNames.matched,
          missingNames: sourceNames.missing,
          expectedCount: sourceNames.expected.length,
          actualCount: sourceNames.matched.length,
          matchedCount: sourceNames.matched.length,
          missingCount: sourceNames.missing.length,
        },
      };
    }
    verification = { title, body, links };
    if (title.ok && body.ok && (links.ok || links.plain_source_names?.ok === true)) {
      return verification;
    }
    await page.waitForTimeout(200);
  } while (Date.now() < deadline);

  if (!verification.links.ok) {
    const sourceNameVerification = verification.links.plain_source_names;
    if (sourceNameVerification && sourceNameVerification.ok !== true) {
      throw new ManualActionError(
        `${platform.label}编辑器未保留正文链接，且有 ${sourceNameVerification.missingCount} 个来源名称未通过校验，系统已中止本次操作。`,
        { content_verification: verification },
      );
    }
    throw new ManualActionError(
      `${platform.label}编辑器未保留 ${verification.links.missingCount} 个正文链接，系统已中止本次操作。`,
      { content_verification: verification },
    );
  }
  const failedParts = [!verification.title.ok ? '标题' : '', !verification.body.ok ? '正文' : ''].filter(Boolean).join('和');
  throw new ManualActionError(`发布前${failedParts}完整性校验失败，系统已中止本次操作，请检查平台编辑器是否变化。`, {
    content_verification: verification,
  });
}

async function readEditableText(locator) {
  if (!locator || typeof locator.evaluate !== 'function') {
    return '';
  }
  return locator.evaluate((element) => {
    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
      return element.value;
    }
    return element.innerText || element.textContent || '';
  }).catch(() => '');
}

async function readEditableLinks(locator) {
  if (!locator || typeof locator.evaluate !== 'function') {
    return [];
  }

  return locator.evaluate((element) => Array.from(element.querySelectorAll('a[href]'))
    .map((anchor) => anchor.href || anchor.getAttribute('href') || '')
    .filter(Boolean)).catch(() => []);
}

function extractHttpLinks(html) {
  const links = [];
  const pattern = /<a\b[^>]*\bhref\s*=\s*(["'])(.*?)\1/giu;
  for (const match of String(html).matchAll(pattern)) {
    const normalized = normalizeHttpUrl(match[2]);
    if (normalized !== null && !links.includes(normalized)) {
      links.push(normalized);
    }
  }
  return links;
}

function extractHttpLinkReferences(html) {
  const references = [];
  const pattern = /<a\b[^>]*\bhref\s*=\s*(["'])(.*?)\1[^>]*>([\s\S]*?)<\/a>/giu;
  for (const match of String(html).matchAll(pattern)) {
    const url = normalizeHttpUrl(match[2]);
    if (url === null) {
      continue;
    }
    references.push({
      url,
      name: htmlToPlainText(match[3]).trim(),
    });
  }
  return references;
}

function assessSourceNameIntegrity(references, actualText) {
  const expected = Array.from(new Set(references.map((reference) => reference.name)));
  const actual = normalizeComparableText(actualText);
  const genericNames = new Set([
    '来源',
    '官方来源',
    '点击查看',
    '查看原文',
    '原文',
    '原文链接',
    '链接',
    '详情',
    '查看详情',
    '这里',
  ].map((name) => normalizeComparableText(name)));
  const matched = [];
  const missing = [];

  for (const name of expected) {
    const normalized = normalizeComparableText(name);
    if (normalized.length >= 2 && !genericNames.has(normalized) && actual.includes(normalized)) {
      matched.push(name);
    } else {
      missing.push(name);
    }
  }

  return {
    ok: expected.length > 0 && missing.length === 0,
    expected,
    matched,
    missing,
  };
}

function assessLinkIntegrity(expectedLinks, actualLinks) {
  const actual = Array.from(new Set((Array.isArray(actualLinks) ? actualLinks : [])
    .map((link) => normalizeHttpUrl(link))
    .filter((link) => link !== null)));
  const missing = expectedLinks.filter((expected) => !actual.includes(expected));

  return {
    required: expectedLinks.length > 0,
    ok: missing.length === 0,
    expectedCount: expectedLinks.length,
    actualCount: actual.length,
    matchedCount: expectedLinks.length - missing.length,
    missingCount: missing.length,
    expectedUrls: expectedLinks,
    actualUrls: actual,
    missingUrls: missing,
  };
}

function normalizeHttpUrl(value) {
  const decoded = String(value)
    .replace(/&amp;/gi, '&')
    .replace(/&#39;/gi, "'")
    .replace(/&quot;/gi, '"')
    .trim();
  try {
    const url = new URL(decoded);
    return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

function approvalText(value, maxLength) {
  if (typeof value !== 'string') {
    return '';
  }
  const normalized = value.trim();
  return normalized !== '' && normalized.length <= maxLength ? normalized : '';
}

function invalidPlainSourceNamesApproval() {
  return new RunnerError(
    'plain_source_names_approval 必须包含 approved=true、platform=sohu、article_distribution_id、approved_by、approved_at 和 64 位小写十六进制 payload_hash。',
    { code: 'invalid_payload', status: 422 },
  );
}

async function readSurfaceText(page, selectors, timeoutMs) {
  const texts = [];
  for (const context of frameContexts(page)) {
    const text = await context.locator('body').evaluate((body, selectorsToRemove) => {
      const clone = body.cloneNode(true);
      for (const selector of selectorsToRemove) {
        try {
          if (typeof clone.matches === 'function' && clone.matches(selector)) {
            return '';
          }
          for (const editable of clone.querySelectorAll(selector)) {
            editable.remove();
          }
        } catch {
          // Ignore an invalid platform selector here; normal control discovery will fail closed later.
        }
      }
      return clone.innerText || clone.textContent || '';
    }, selectors, { timeout: timeoutMs }).catch(() => '');
    if (text.trim() !== '') {
      texts.push(text);
    }
  }
  return texts.join('\n');
}

function pageStateDetails(state) {
  return {
    url: state.url,
    blockingText: state.blockingText,
    looksLikeLogin: state.looksLikeLogin,
  };
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

async function dismissEditorOverlays(page, platform, timeoutMs) {
  const overlaySelectors = Array.isArray(platform.editorOverlaySelectors)
    ? platform.editorOverlaySelectors.filter((selector) => typeof selector === 'string' && selector.trim() !== '')
    : [];
  if (overlaySelectors.length === 0 || !await findVisibleOnce(page, overlaySelectors)) {
    return;
  }

  const closeSelectors = Array.isArray(platform.editorOverlayCloseSelectors)
    ? platform.editorOverlayCloseSelectors.filter((selector) => typeof selector === 'string' && selector.trim() !== '')
    : [];
  const closeControls = await visibleLocatorsForSelectors(page, closeSelectors);
  if (closeControls.length !== 1) {
    throw new ManualActionError(`${platform.label}编辑器遮罩关闭控件不唯一，系统已中止本次操作。`, {
      url: page.url(),
      editor_overlay_selectors: overlaySelectors,
      editor_overlay_close_selectors: closeSelectors,
      visible_editor_overlay_close_controls: closeControls.length,
    });
  }

  try {
    await closeControls[0].click({ timeout: timeoutMs });
  } catch {
    throw new ManualActionError(`${platform.label}编辑器遮罩关闭控件点击失败，系统已中止本次操作。`, {
      url: page.url(),
      editor_overlay_selectors: overlaySelectors,
      editor_overlay_close_selectors: closeSelectors,
      editor_overlay_close_click_failed: true,
    });
  }

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (!await findVisibleOnce(page, overlaySelectors)) {
      return;
    }
    await page.waitForTimeout(Math.min(100, Math.max(1, deadline - Date.now())));
  }
  if (!await findVisibleOnce(page, overlaySelectors)) {
    return;
  }

  throw new ManualActionError(`${platform.label}编辑器遮罩未能安全关闭，系统已中止本次操作。`, {
    url: page.url(),
    editor_overlay_selectors: overlaySelectors,
    editor_overlay_close_selectors: closeSelectors,
    editor_overlay_still_visible: true,
  });
}

async function visibleLocatorsForSelectors(page, selectors) {
  if (selectors.length === 0) {
    return [];
  }

  const visible = [];
  const combinedSelector = selectors.join(', ');
  for (const context of frameContexts(page)) {
    const candidates = context.locator(combinedSelector);
    const count = await candidates.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const candidate = candidates.nth(index);
      if (await candidate.isVisible().catch(() => false)) {
        visible.push(candidate);
      }
    }
  }
  return visible;
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
    const match = await findUniqueVisibleTextOnce(
      page,
      texts,
      '检测到多个可见的封面设置入口，无法安全判断目标，系统已中止本次操作。',
    );
    if (match) {
      return match;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return null;
}

async function findUniqueVisibleTextOnce(page, texts, ambiguousMessage) {
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
    throw new ManualActionError(ambiguousMessage);
  }
  return matches[0] ?? null;
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

async function waitForCoverConfirmationOutcome(page, scope, retryTexts, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  const canRetry = Array.isArray(retryTexts) && retryTexts.length > 0;
  while (Date.now() < deadline) {
    if (!await scope.isVisible().catch(() => false)) {
      return { closed: true, retryNotice: null };
    }
    if (canRetry) {
      const retryNotice = await findUniqueVisibleTextOnce(
        page,
        retryTexts,
        '检测到多个可见的封面裁剪处理中提示，无法安全判断目标，系统已中止本次操作。',
      );
      if (retryNotice) {
        return { closed: false, retryNotice };
      }
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }
  return {
    closed: !await scope.isVisible().catch(() => false),
    retryNotice: null,
  };
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

async function clickButtonByTexts(page, texts, timeoutMs, onBeforeClick) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    for (const text of texts) {
      const candidates = page.getByRole('button', { name: text, exact: false });
      const count = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = count - 1; index >= 0; index -= 1) {
        const candidate = candidates.nth(index);
        if (await candidate.isVisible().catch(() => false) && await candidate.isEnabled().catch(() => false)) {
          await onBeforeClick?.();
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
  let lastAmbiguousBlockingState = null;
  while (Date.now() < deadline) {
    const state = await detectPageState(page, platform);
    if (state.blockingText === '') {
      lastAmbiguousBlockingState = null;
    }
    if (state.blockingText !== '' && state.blockingText !== '安全验证') {
      throw new ManualActionError(
        `平台当前显示“${state.blockingText}”，本次发布无法确认成功，请处理后重试。`,
        pageStateDetails(state),
      );
    }
    const outcomeText = state.blockingText === '安全验证'
      ? state.surfaceText.replaceAll('安全验证', '')
      : state.surfaceText;
    const remainingBlockingText = state.blockingText === '安全验证'
      ? findBlockingText(outcomeText, platform)
      : '';
    if (remainingBlockingText !== '') {
      const blockedState = { ...state, blockingText: remainingBlockingText };
      throw new ManualActionError(
        `平台当前显示“${remainingBlockingText}”，本次发布无法确认成功，请处理后重试。`,
        pageStateDetails(blockedState),
      );
    }
    const currentUrl = page.url();
    const platformUrlOutcome = classifyPlatformSuccessUrlEvidence(currentUrl, platform, publishMode);
    if (currentUrl !== originalUrl && platformUrlOutcome) {
      return platformUrlOutcome;
    }
    const noticeOutcome = await findVisibleOutcomeNotice(page, platform, publishMode);
    if (noticeOutcome) {
      return noticeOutcome;
    }
    const textOutcome = classifyOutcomeEvidence(outcomeText, platform, publishMode);
    if (textOutcome) {
      return textOutcome;
    }
    if (state.blockingText !== '') {
      lastAmbiguousBlockingState = state;
      await page.waitForTimeout(600);
      continue;
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
  if (lastAmbiguousBlockingState) {
    throw new ManualActionError(
      `平台当前显示“${lastAmbiguousBlockingState.blockingText}”，本次发布无法确认成功，请处理后重试。`,
      pageStateDetails(lastAmbiguousBlockingState),
    );
  }
  throw new ManualActionError('平台未返回可确认的发布成功状态，请在浏览器中核对，系统不会把本次任务标记为成功。', { url: page.url() });
}

async function findVisibleOutcomeNotice(page, platform, publishMode) {
  const selectors = platform.outcomeNoticeSelectors ?? [];
  if (!Array.isArray(selectors) || selectors.length === 0) {
    return null;
  }

  const expected = [
    ...(publishMode === 'publish'
      ? (platform.reviewingNoticeTexts ?? []).map((text) => ({
          text,
          status: 'reviewing',
          source: 'explicit_reviewing_text',
        }))
      : []),
    ...(platform.successNoticeTexts ?? []).map((text) => ({
      text,
      status: publishMode === 'draft' ? 'draft' : 'published',
      source: publishMode === 'draft' ? 'explicit_draft_text' : 'explicit_success_text',
    })),
  ].filter((entry) => typeof entry.text === 'string' && entry.text.trim() !== '');
  if (expected.length === 0) {
    return null;
  }

  const matches = [];
  for (const context of frameContexts(page)) {
    for (const selector of selectors) {
      const candidates = context.locator(selector);
      const count = Math.min(await candidates.count().catch(() => 0), 10);
      for (let index = 0; index < count; index += 1) {
        const candidate = candidates.nth(index);
        if (!await candidate.isVisible().catch(() => false)) {
          continue;
        }
        const actualText = String(await candidate.innerText().catch(() => '')).trim();
        const outcome = expected.find((entry) => actualText === entry.text.trim());
        if (outcome) {
          matches.push(outcome);
        }
      }
    }
  }

  return matches.length === 1 ? matches[0] : null;
}
