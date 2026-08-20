import assert from 'node:assert/strict';
import test from 'node:test';
import { applyAiDisclosure } from '../src/ai-disclosure.js';
import { ManualActionError } from '../src/errors.js';

function locatorFor(candidates) {
  return {
    count: async () => candidates.length,
    nth: (index) => candidates[index],
  };
}

const zhihuSelectedValueSelector = '[data-ai-disclosure-selected="true"]';

test('applies an AI disclosure only after both controls are uniquely visible and positively verified', async () => {
  const calls = [];
  let optionVisible = false;
  const node = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
  };
  const trigger = {
    isVisible: async () => true,
    click: async () => {
      calls.push('trigger');
      optionVisible = true;
    },
  };
  const option = {
    isVisible: async () => optionVisible,
    click: async () => {
      calls.push('option');
      node.checked = true;
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    locator: (selector) => (
      selector === '[data-ai-trigger]' ? locatorFor([trigger]) : locatorFor([])
    ),
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      return text === '包含 AI 辅助创作' ? locatorFor([option]) : locatorFor([]);
    },
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    triggerSelectors: ['[data-ai-trigger]'],
    optionTexts: ['包含 AI 辅助创作'],
    timeoutMs: 20,
  });

  assert.deepEqual(calls, ['trigger', 'option']);
  assert.deepEqual(verification, {
    required: true,
    platform: 'zhihu',
    selected: true,
    option_text: '包含 AI 辅助创作',
    evidence: {
      attribute: 'checked',
      value: true,
    },
  });
});

test('accepts one configured visible selected-state selector as auditable positive evidence', async () => {
  const selectedEvidenceSelector = '.one-checkbox-wrapper:has-text("采用AI生成内容") .one-checkbox.one-checkbox-checked';
  let selected = false;
  const node = {
    checked: false,
    selected: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      selected = true;
    },
    evaluate: async (callback) => callback(node),
  };
  const selectedState = {
    isVisible: async () => selected,
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: (selector) => selector === selectedEvidenceSelector
      ? locatorFor([selectedState])
      : empty,
    getByText: (text, options) => {
      assert.equal(text, '采用AI生成内容');
      assert.deepEqual(options, { exact: true });
      return locatorFor([option]);
    },
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'baijiahao', {
    optionTexts: ['采用AI生成内容'],
    selectedEvidenceSelectors: [selectedEvidenceSelector],
    timeoutMs: 20,
  });

  assert.equal(verification.selected, true);
  assert.deepEqual(verification.evidence, {
    attribute: 'selector_state',
    value: selectedEvidenceSelector,
  });
});

test('returns an already-selected configured disclosure without clicking it again', async () => {
  const selectedEvidenceSelector = '.one-checkbox-wrapper:has-text("采用AI生成内容") .one-checkbox.one-checkbox-checked';
  let clickCount = 0;
  const node = {
    checked: false,
    selected: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
    evaluate: async (callback) => callback(node),
  };
  const selectedState = {
    isVisible: async () => true,
  };
  const page = {
    frames: () => [],
    locator: (selector) => selector === selectedEvidenceSelector
      ? locatorFor([selectedState])
      : locatorFor([]),
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'baijiahao', {
    optionTexts: ['采用AI生成内容'],
    selectedEvidenceSelectors: [selectedEvidenceSelector],
    timeoutMs: 20,
  });

  assert.equal(clickCount, 0);
  assert.deepEqual(verification, {
    required: true,
    platform: 'baijiahao',
    selected: true,
    option_text: '采用AI生成内容',
    evidence: {
      attribute: 'selector_state',
      value: selectedEvidenceSelector,
    },
  });
});

test('returns an already-selected native disclosure control without clicking it again', async () => {
  let clickCount = 0;
  const node = {
    checked: true,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'toutiao', {
    optionTexts: ['引用AI'],
    timeoutMs: 20,
  });

  assert.equal(clickCount, 0);
  assert.deepEqual(verification.evidence, {
    attribute: 'checked',
    value: true,
  });
});

test('fails closed when the configured selected-state selector never becomes visible', async () => {
  let clickCount = 0;
  const node = {
    checked: false,
    selected: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    locator: () => locatorFor([]),
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'baijiahao', {
      optionTexts: ['采用AI生成内容'],
      selectedEvidenceSelectors: ['.one-checkbox.one-checkbox-checked'],
      timeoutMs: 1,
    }),
    (error) => error instanceof ManualActionError && error.code === 'manual_action_required',
  );
  assert.equal(clickCount, 1);
});

test('fails closed when the configured selected-state selector matches more than one visible control', async () => {
  let clickCount = 0;
  const node = {
    checked: false,
    selected: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
    evaluate: async (callback) => callback(node),
  };
  const selectedState = {
    isVisible: async () => true,
  };
  const selectedEvidenceSelector = '.one-checkbox.one-checkbox-checked';
  const page = {
    frames: () => [],
    locator: (selector) => selector === selectedEvidenceSelector
      ? locatorFor([selectedState, selectedState])
      : locatorFor([]),
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'baijiahao', {
      optionTexts: ['采用AI生成内容'],
      selectedEvidenceSelectors: [selectedEvidenceSelector],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && error.code === 'manual_action_required',
  );
  assert.equal(clickCount, 0);
});

test('select-value disclosure rejects matching text that is not the configured selected control', async () => {
  const staticText = {
    isVisible: async () => true,
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: () => empty,
    getByText: (text) => text === '包含 AI 辅助创作 作者对内容负责'
      ? locatorFor([staticText])
      : empty,
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'zhihu', {
      mode: 'select_value',
      triggerTexts: ['无声明'],
      optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
      selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
      selectedValueSelectors: ['[data-ai-disclosure-selected="true"]'],
      unselectedValueTexts: ['无声明'],
      timeoutMs: 1,
    }),
    (error) => error instanceof ManualActionError && error.code === 'manual_action_required',
  );
});

test('select-value disclosure requires the chosen value to persist after the menu closes', async () => {
  const calls = [];
  let menuOpen = false;
  let selected = false;
  const trigger = {
    isVisible: async () => !selected,
    click: async () => {
      calls.push('trigger');
      menuOpen = true;
    },
  };
  const option = {
    isVisible: async () => menuOpen,
    click: async () => {
      calls.push('option');
      menuOpen = false;
      selected = true;
    },
  };
  const selectedValue = {
    isVisible: async () => selected,
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: (selector) => selector === zhihuSelectedValueSelector && selected
      ? locatorFor([selectedValue])
      : empty,
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      if (text === '无声明') {
        return !selected ? locatorFor([trigger]) : empty;
      }
      if (text === '包含 AI 辅助创作 作者对内容负责') {
        if (menuOpen) {
          return locatorFor([option]);
        }
        return selected ? locatorFor([selectedValue]) : empty;
      }
      return empty;
    },
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    mode: 'select_value',
    triggerTexts: ['无声明'],
    optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueSelectors: [zhihuSelectedValueSelector],
    unselectedValueTexts: ['无声明'],
    timeoutMs: 20,
  });

  assert.deepEqual(calls, ['trigger', 'option']);
  assert.deepEqual(verification, {
    required: true,
    platform: 'zhihu',
    selected: true,
    option_text: '包含 AI 辅助创作 作者对内容负责',
    evidence: {
      attribute: 'selector_state',
      value: zhihuSelectedValueSelector,
    },
  });
});

test('select-value disclosure is idempotent when the persistent value is already selected', async () => {
  let clickCount = 0;
  const selectedValue = {
    isVisible: async () => true,
    click: async () => { clickCount += 1; },
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: (selector) => selector === zhihuSelectedValueSelector
      ? locatorFor([selectedValue])
      : empty,
    getByText: (text) => text === '包含 AI 辅助创作 作者对内容负责'
      ? locatorFor([selectedValue])
      : empty,
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    mode: 'select_value',
    triggerTexts: ['无声明'],
    optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueSelectors: [zhihuSelectedValueSelector],
    unselectedValueTexts: ['无声明'],
    timeoutMs: 20,
  });

  assert.equal(clickCount, 0);
  assert.equal(verification.selected, true);
  assert.deepEqual(verification.evidence, {
    attribute: 'selector_state',
    value: zhihuSelectedValueSelector,
  });
});

test('select-value disclosure collapses nested controls at the same visual selected value', async () => {
  const outer = {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 100, y: 200, width: 220, height: 32 }),
  };
  const inner = {
    isVisible: async () => true,
    boundingBox: async () => ({ x: 108, y: 205, width: 180, height: 20 }),
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: (selector) => selector === zhihuSelectedValueSelector
      ? locatorFor([outer, inner])
      : empty,
    getByText: (text) => text === '包含 AI 辅助创作 作者对内容负责'
      ? locatorFor([outer, inner])
      : empty,
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    mode: 'select_value',
    triggerTexts: ['无声明'],
    optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueSelectors: [zhihuSelectedValueSelector],
    unselectedValueTexts: ['无声明'],
    timeoutMs: 20,
  });

  assert.equal(verification.selected, true);
  assert.equal(verification.evidence.value, zhihuSelectedValueSelector);
});

test('select-value disclosure reuses an already-open menu without toggling it closed', async () => {
  const calls = [];
  let menuOpen = true;
  let selected = false;
  const trigger = {
    isVisible: async () => !selected,
    click: async () => {
      calls.push('trigger');
      menuOpen = !menuOpen;
    },
  };
  const optionOuter = {
    isVisible: async () => menuOpen,
    boundingBox: async () => ({ x: 100, y: 260, width: 260, height: 36 }),
    click: async () => {
      calls.push('option');
      menuOpen = false;
      selected = true;
    },
  };
  const optionInner = {
    isVisible: async () => menuOpen,
    boundingBox: async () => ({ x: 108, y: 266, width: 230, height: 24 }),
    click: async () => {
      calls.push('option');
      menuOpen = false;
      selected = true;
    },
  };
  const selectedValue = {
    isVisible: async () => selected,
    boundingBox: async () => ({ x: 100, y: 260, width: 260, height: 36 }),
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    locator: (selector) => selector === zhihuSelectedValueSelector && selected
      ? locatorFor([selectedValue])
      : empty,
    getByText: (text) => {
      if (text === '无声明') {
        return !selected ? locatorFor([trigger]) : empty;
      }
      if (text === '包含 AI 辅助创作 作者对内容负责') {
        if (menuOpen) {
          return locatorFor([optionOuter, optionInner]);
        }
        return selected ? locatorFor([selectedValue]) : empty;
      }
      return empty;
    },
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    mode: 'select_value',
    triggerTexts: ['无声明'],
    optionTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueTexts: ['包含 AI 辅助创作 作者对内容负责'],
    selectedValueSelectors: [zhihuSelectedValueSelector],
    unselectedValueTexts: ['无声明'],
    timeoutMs: 20,
  });

  assert.deepEqual(calls, ['option']);
  assert.equal(verification.selected, true);
});

test('supports a directly visible disclosure option when the platform has no trigger control', async () => {
  let clicked = false;
  const node = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clicked = true;
      node.checked = true;
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    locator: (selector) => (
      selector === '[data-ai-option]' ? locatorFor([option]) : locatorFor([])
    ),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'sohu', {
    optionSelectors: ['[data-ai-option]'],
    timeoutMs: 20,
  });

  assert.equal(clicked, true);
  assert.equal(verification.selected, true);
  assert.equal(verification.evidence.attribute, 'checked');
});

test('waits for an asynchronously selected native disclosure state', async () => {
  const node = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      setTimeout(() => { node.checked = true; }, 5);
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async (milliseconds) => new Promise((resolve) => setTimeout(resolve, Math.min(milliseconds, 5))),
  };

  const verification = await applyAiDisclosure(page, 'toutiao', {
    optionTexts: ['引用AI'],
    timeoutMs: 30,
  });

  assert.equal(verification.selected, true);
  assert.deepEqual(verification.evidence, {
    attribute: 'checked',
    value: true,
  });
});

test('accepts aria-checked as positive selection evidence', async () => {
  let ariaChecked = 'false';
  const node = {
    getAttribute: (name) => name === 'aria-checked' ? ariaChecked : null,
    classList: { contains: () => false },
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      ariaChecked = 'true';
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    locator: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    optionSelectors: ['[role="option"]'],
    timeoutMs: 20,
  });

  assert.deepEqual(verification.evidence, {
    attribute: 'aria-checked',
    value: 'true',
  });
});

test('accepts aria-selected as positive selection evidence', async () => {
  let ariaSelected = 'false';
  const node = {
    getAttribute: (name) => name === 'aria-selected' ? ariaSelected : null,
    classList: { contains: () => false },
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      ariaSelected = 'true';
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    locator: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'zhihu', {
    optionSelectors: ['[role="option"]'],
    timeoutMs: 20,
  });

  assert.deepEqual(verification.evidence, {
    attribute: 'aria-selected',
    value: 'true',
  });
});

test('locates a unique visible option by its configured exact text without returning page content', async () => {
  const node = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      node.checked = true;
    },
    evaluate: async (callback) => callback(node),
  };
  const empty = locatorFor([]);
  const page = {
    frames: () => [],
    getByText: (text, options) => {
      assert.deepEqual(options, { exact: true });
      return text === '引用AI' ? locatorFor([option]) : empty;
    },
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'toutiao', {
    optionTexts: ['引用AI'],
    timeoutMs: 20,
  });

  assert.equal(verification.option_text, '引用AI');
  assert.deepEqual(Object.keys(verification).sort(), [
    'evidence',
    'option_text',
    'platform',
    'required',
    'selected',
  ]);
});

test('verifies a text candidate through the checkbox inside its nearest label', async () => {
  const input = {
    checked: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
  };
  const label = {
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: (selector) => selector === 'input' ? [input] : [],
  };
  const textNode = {
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: (selector) => selector === 'label' ? label : null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      input.checked = true;
    },
    evaluate: async (callback) => callback(textNode),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'toutiao', {
    optionTexts: ['引用AI'],
    timeoutMs: 20,
  });

  assert.deepEqual(verification.evidence, {
    attribute: 'checked',
    value: true,
  });
});

test('fails closed with ManualActionError when disclosure configuration is missing', async () => {
  await assert.rejects(
    applyAiDisclosure({ frames: () => [] }, 'toutiao'),
    (error) => error instanceof ManualActionError && error.code === 'manual_action_required',
  );
});

test('does not click when more than one disclosure option is visible', async () => {
  let clickCount = 0;
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option, option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'sohu', {
      optionTexts: ['包含AI创作内容'],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && /多个可见的AI 声明选项/.test(error.message),
  );
  assert.equal(clickCount, 0);
});

test('does not click when more than one disclosure trigger is visible', async () => {
  let clickCount = 0;
  const trigger = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
  };
  const page = {
    frames: () => [],
    locator: () => locatorFor([trigger, trigger]),
    getByText: () => assert.fail('Options must not be inspected after trigger ambiguity.'),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'zhihu', {
      triggerSelectors: ['button:has-text("发布设置")'],
      optionTexts: ['包含 AI 辅助创作'],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && /多个可见的AI 声明入口/.test(error.message),
  );
  assert.equal(clickCount, 0);
});

test('fails closed when the configured disclosure option is missing', async () => {
  const page = {
    frames: () => [],
    getByText: () => locatorFor([]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'toutiao', {
      optionTexts: ['引用AI'],
      timeoutMs: 1,
    }),
    (error) => error instanceof ManualActionError && /未找到唯一可见的AI 声明选项/.test(error.message),
  );
});

test('fails closed after clicking when no positive selected-state evidence exists', async () => {
  let clickCount = 0;
  const node = {
    checked: false,
    selected: false,
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      clickCount += 1;
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'zhihu', {
      optionTexts: ['包含 AI 辅助创作'],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && /无法确认已选中/.test(error.message),
  );
  assert.equal(clickCount, 1);
});

test('turns an option click failure into a non-sensitive ManualActionError', async () => {
  const option = {
    isVisible: async () => true,
    click: async () => {
      throw new Error('private DOM details');
    },
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'toutiao', {
      optionTexts: ['引用AI'],
      timeoutMs: 20,
    }),
    (error) => (
      error instanceof ManualActionError
      && !error.message.includes('private DOM details')
      && error.details
      && Object.keys(error.details).length === 0
    ),
  );
});

test('accepts an explicitly selected data-state as positive evidence', async () => {
  let dataState = 'unselected';
  const node = {
    getAttribute: (name) => name === 'data-state' ? dataState : null,
    classList: { contains: () => false },
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      dataState = 'selected';
    },
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  const verification = await applyAiDisclosure(page, 'sohu', {
    optionTexts: ['包含AI创作内容'],
    timeoutMs: 20,
  });

  assert.deepEqual(verification.evidence, {
    attribute: 'data-state',
    value: 'selected',
  });
});

test('fails closed when data-state active is only a focus or highlight state', async () => {
  const node = {
    checked: false,
    selected: false,
    getAttribute: (name) => name === 'data-state' ? 'active' : null,
    querySelectorAll: () => [],
    closest: () => null,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {},
    evaluate: async (callback) => callback(node),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'sohu', {
      optionTexts: ['包含AI创作内容'],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && /无法确认已选中/.test(error.message),
  );
});

test('fails closed when only a generic selected class appears on the nearest role container', async () => {
  let selected = false;
  const roleContainer = {
    getAttribute: () => null,
    classList: { contains: (className) => className === 'selected' && selected },
    querySelectorAll: () => [],
  };
  const textNode = {
    getAttribute: () => null,
    classList: { contains: () => false },
    querySelectorAll: () => [],
    closest: (selector) => selector === 'label' ? null : roleContainer,
  };
  const option = {
    isVisible: async () => true,
    click: async () => {
      selected = true;
    },
    evaluate: async (callback) => callback(textNode),
  };
  const page = {
    frames: () => [],
    getByText: () => locatorFor([option]),
    waitForTimeout: async () => {},
  };

  await assert.rejects(
    applyAiDisclosure(page, 'zhihu', {
      optionTexts: ['包含 AI 辅助创作'],
      timeoutMs: 20,
    }),
    (error) => error instanceof ManualActionError && /无法确认已选中/.test(error.message),
  );
});
