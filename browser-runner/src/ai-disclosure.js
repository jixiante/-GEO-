import { ManualActionError } from './errors.js';

export async function applyAiDisclosure(page, platformKey, config) {
  if (!config || typeof config !== 'object') {
    throw new ManualActionError(`${platformKey} 的 AI 声明自动化配置不完整，系统已中止本次操作。`);
  }

  const timeoutMs = config.timeoutMs ?? 10000;
  if (config.mode === 'select_value') {
    return applySelectValueDisclosure(page, platformKey, config, timeoutMs);
  }

  const existingConfiguredEvidence = await readConfiguredSelectedEvidenceOnce(page, config);
  if (existingConfiguredEvidence) {
    return selectionVerification(platformKey, config, existingConfiguredEvidence);
  }

  if (hasLocatorConfig(config.triggerSelectors, config.triggerTexts)) {
    const trigger = await waitForUniqueVisibleControl(
      page,
      {
        selectors: config.triggerSelectors,
        texts: config.triggerTexts,
      },
      timeoutMs,
      'AI 声明入口',
    );
    await clickControl(trigger.locator, platformKey, 'AI 声明入口');
  }

  const option = await waitForUniqueVisibleControl(
    page,
    {
      selectors: config.optionSelectors,
      texts: config.optionTexts,
    },
    timeoutMs,
    'AI 声明选项',
  );

  const visibleConfiguredEvidence = await readConfiguredSelectedEvidenceOnce(page, config);
  if (visibleConfiguredEvidence) {
    return selectionVerification(platformKey, config, visibleConfiguredEvidence, option.text);
  }

  let evidence = await readNativeSelectedEvidence(option.locator);
  if (evidence) {
    return selectionVerification(platformKey, config, evidence, option.text);
  }

  await clickControl(option.locator, platformKey, 'AI 声明选项');
  evidence = await waitForSelectedEvidence(page, option.locator, config, timeoutMs);

  if (!evidence) {
    throw new ManualActionError(`${platformKey} 的 AI 声明选项无法确认已选中，系统已中止本次操作。`);
  }

  return selectionVerification(platformKey, config, evidence, option.text);
}

async function readNativeSelectedEvidence(locator) {
  if (typeof locator?.evaluate !== 'function') {
    return null;
  }

  return locator.evaluate((node) => {
    const candidates = [];
    const seen = new Set();
    const add = (candidate) => {
      if (!candidate || seen.has(candidate)) {
        return;
      }
      seen.add(candidate);
      candidates.push(candidate);
    };
    const addWithInputs = (candidate) => {
      add(candidate);
      for (const input of candidate?.querySelectorAll?.('input') ?? []) {
        add(input);
      }
    };

    addWithInputs(node);
    addWithInputs(node.closest?.('label'));
    addWithInputs(node.closest?.(
      '[role="checkbox"], [role="radio"], [role="option"], [role="menuitemradio"], [role="switch"]',
    ));

    for (const candidate of candidates) {
      if (candidate.checked === true) {
        return { attribute: 'checked', value: true };
      }
      if (candidate.selected === true) {
        return { attribute: 'selected', value: true };
      }

      const ariaChecked = candidate.getAttribute?.('aria-checked');
      if (ariaChecked?.toLowerCase() === 'true') {
        return { attribute: 'aria-checked', value: 'true' };
      }
      const ariaSelected = candidate.getAttribute?.('aria-selected');
      if (ariaSelected?.toLowerCase() === 'true') {
        return { attribute: 'aria-selected', value: 'true' };
      }

      const dataState = candidate.getAttribute?.('data-state')?.toLowerCase();
      if (['checked', 'selected', 'on', 'true'].includes(dataState)) {
        return { attribute: 'data-state', value: dataState };
      }

    }
    return null;
  }).catch(() => null);
}

async function readConfiguredSelectedEvidenceOnce(page, config) {
  if (!hasLocatorConfig(config.selectedEvidenceSelectors)) {
    return null;
  }

  const selectedState = await findUniqueVisibleControlOnce(
    page,
    {
      selectors: config.selectedEvidenceSelectors,
    },
    'AI 声明已选状态',
  );
  if (!selectedState) {
    return null;
  }

  return {
    attribute: 'selector_state',
    value: config.selectedEvidenceSelectors.join(', '),
  };
}

async function waitForSelectedEvidence(page, optionLocator, config, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const nativeEvidence = await readNativeSelectedEvidence(optionLocator);
    if (nativeEvidence) {
      return nativeEvidence;
    }

    const configuredEvidence = await readConfiguredSelectedEvidenceOnce(page, config);
    if (configuredEvidence) {
      return configuredEvidence;
    }

    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }

  return null;
}

async function applySelectValueDisclosure(page, platformKey, config, timeoutMs) {
  if (
    !hasLocatorConfig(config.triggerSelectors, config.triggerTexts)
    || !hasLocatorConfig(config.optionSelectors, config.optionTexts)
    || !hasLocatorConfig(config.selectedValueSelectors)
    || !Array.isArray(config.selectedValueTexts)
    || config.selectedValueTexts.length === 0
    || !Array.isArray(config.unselectedValueTexts)
    || config.unselectedValueTexts.length === 0
  ) {
    throw new ManualActionError(`${platformKey} 的 AI 声明下拉配置不完整，系统已中止本次操作。`);
  }

  const existingEvidence = await readPersistentSelectedValue(page, config);
  if (existingEvidence) {
    return selectionVerification(platformKey, config, existingEvidence);
  }

  let option = await findUniqueVisibleControlOnce(
    page,
    {
      selectors: config.optionSelectors,
      texts: config.optionTexts,
    },
    'AI 声明选项',
  );
  if (!option) {
    const trigger = await waitForUniqueVisibleControl(
      page,
      {
        selectors: config.triggerSelectors,
        texts: config.triggerTexts,
      },
      timeoutMs,
      'AI 声明入口',
    );
    await clickControl(trigger.locator, platformKey, 'AI 声明入口');

    option = await waitForUniqueVisibleControl(
      page,
      {
        selectors: config.optionSelectors,
        texts: config.optionTexts,
      },
      timeoutMs,
      'AI 声明选项',
    );
  }
  await clickControl(option.locator, platformKey, 'AI 声明选项');

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const evidence = await readPersistentSelectedValue(page, config);
    if (evidence) {
      return selectionVerification(platformKey, config, evidence, option.text);
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }

  throw new ManualActionError(`${platformKey} 的 AI 声明选项未形成持久选中值，系统已中止本次操作。`);
}

function selectionVerification(platformKey, config, evidence, optionText = null) {
  return {
    required: true,
    platform: platformKey,
    selected: true,
    option_text: optionText ?? config.optionTexts?.[0] ?? null,
    evidence,
  };
}

async function readPersistentSelectedValue(page, config) {
  const unselectedMatches = await visibleExactTextMatches(page, config.unselectedValueTexts);
  if (unselectedMatches.length > 0) {
    return null;
  }

  const selectedControl = await findUniqueVisibleControlOnce(
    page,
    {
      selectors: config.selectedValueSelectors,
    },
    'AI 声明已选值',
  );
  if (!selectedControl) {
    return null;
  }

  return {
    attribute: 'selector_state',
    value: config.selectedValueSelectors.join(', '),
  };
}

async function visibleExactTextMatches(page, texts) {
  const matches = [];
  const exactTexts = [...new Set(
    (texts ?? []).filter((text) => typeof text === 'string' && text.length > 0),
  )];
  const frames = page.frames();
  const contexts = frames.length > 0 ? frames : [page];
  for (const context of contexts) {
    for (const text of exactTexts) {
      const candidates = context.getByText(text, { exact: true });
      const count = await candidates.count().catch(() => 0);
      for (let index = 0; index < count; index += 1) {
        const candidate = candidates.nth(index);
        if (await candidate.isVisible().catch(() => false)) {
          matches.push({ locator: candidate, text });
        }
      }
    }
  }
  return collapseOverlappingTextMatches(matches);
}

async function collapseOverlappingTextMatches(matches) {
  if (matches.length <= 1) {
    return matches;
  }

  const measured = [];
  for (const match of matches) {
    if (typeof match.locator?.boundingBox !== 'function') {
      return matches;
    }
    const box = await match.locator.boundingBox().catch(() => null);
    if (!box || box.width <= 0 || box.height <= 0) {
      return matches;
    }
    measured.push({ match, box, area: box.width * box.height });
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

  return groups.map((group) => group.sort((left, right) => left.area - right.area)[0].match);
}

function boxesRepresentSameVisualTarget(left, right) {
  const intersectionWidth = Math.max(
    0,
    Math.min(left.x + left.width, right.x + right.width) - Math.max(left.x, right.x),
  );
  const intersectionHeight = Math.max(
    0,
    Math.min(left.y + left.height, right.y + right.height) - Math.max(left.y, right.y),
  );
  const intersectionArea = intersectionWidth * intersectionHeight;
  const smallerArea = Math.min(left.width * left.height, right.width * right.height);

  return smallerArea > 0 && intersectionArea / smallerArea >= 0.65;
}

function hasLocatorConfig(selectors, texts) {
  return (
    Array.isArray(selectors) && selectors.length > 0
  ) || (
    Array.isArray(texts) && texts.length > 0
  );
}

async function clickControl(locator, platformKey, controlLabel) {
  try {
    await locator.click();
  } catch {
    throw new ManualActionError(`${platformKey} 的${controlLabel}无法安全操作，系统已中止本次操作。`);
  }
}

async function waitForUniqueVisibleControl(page, { selectors, texts }, timeoutMs, controlLabel) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const match = await findUniqueVisibleControlOnce(page, { selectors, texts }, controlLabel);
    if (match) {
      return match;
    }
    await page.waitForTimeout(Math.min(300, Math.max(1, deadline - Date.now())));
  }

  throw new ManualActionError(`未找到唯一可见的${controlLabel}，系统已中止本次操作。`);
}

async function findUniqueVisibleControlOnce(page, { selectors, texts }, controlLabel) {
  const selector = Array.isArray(selectors) && selectors.length > 0
    ? selectors.join(', ')
    : null;
  const exactTexts = selector
    ? []
    : [...new Set((texts ?? []).filter((text) => typeof text === 'string' && text.length > 0))];

  if (!selector && exactTexts.length === 0) {
    throw new ManualActionError(`${controlLabel}自动化配置不完整，系统已中止本次操作。`);
  }

  const matches = [];
  const frames = page.frames();
  const contexts = frames.length > 0 ? frames : [page];

  for (const context of contexts) {
    if (selector) {
      const candidates = context.locator(selector);
      const count = await candidates.count().catch(() => 0);
      for (let index = 0; index < count; index += 1) {
        const candidate = candidates.nth(index);
        if (await candidate.isVisible().catch(() => false)) {
          matches.push({ locator: candidate, text: null });
        }
      }
    } else {
      for (const text of exactTexts) {
        const candidates = context.getByText(text, { exact: true });
        const count = await candidates.count().catch(() => 0);
        for (let index = 0; index < count; index += 1) {
          const candidate = candidates.nth(index);
          if (await candidate.isVisible().catch(() => false)) {
            matches.push({ locator: candidate, text });
          }
        }
      }
    }
  }

  const visualMatches = await collapseOverlappingTextMatches(matches);
  if (visualMatches.length > 1) {
    throw new ManualActionError(`检测到多个可见的${controlLabel}，无法安全判断目标，系统已中止本次操作。`);
  }
  return visualMatches[0] ?? null;
}
