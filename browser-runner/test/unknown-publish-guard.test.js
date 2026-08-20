import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { BrowserRunner } from '../src/browser-runner.js';
import { ManualActionError } from '../src/errors.js';
import { StateStore } from '../src/state-store.js';

test('authenticated health exposes the current verification contract version', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-health-contract-'));
  const runner = runnerFor(directory, new StateStore(path.join(directory, 'state.json')), null);

  assert.equal(runner.health().verification_contract_version, 2);
});

test('a submit attempt is durably recorded with an unknown outcome', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-pending-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);

  assert.equal(store.markPending('publish-unknown-1', {
    platform: 'toutiao',
    account_id: 'default',
  }), true);

  const reloaded = new StateStore(statePath);
  assert.deepEqual(reloaded.getPending('publish-unknown-1'), {
    state: 'pending',
    outcome: 'unknown',
    platform: 'toutiao',
    account_id: 'default',
    stored_at: reloaded.getPending('publish-unknown-1').stored_at,
  });
});

test('a restarted runner refuses to resubmit an idempotency key with an unknown outcome', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-restart-'));
  const statePath = path.join(directory, 'state.json');
  new StateStore(statePath).markPending('publish-unknown-2', {
    platform: 'toutiao',
    account_id: 'default',
  });

  const reloaded = new StateStore(statePath);
  let browserOpened = false;
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(directory, 'STOP'),
    screenshotsDir: directory,
    operationTimeoutMs: 100,
  }, reloaded, { write() {} });
  runner.context = async () => {
    browserOpened = true;
    throw new Error('The browser must not open for an unknown prior outcome.');
  };

  await assert.rejects(
    runner.publish(publishRequest('publish-unknown-2')),
    (error) => error instanceof ManualActionError
      && error.details.idempotency_key === 'publish-unknown-2'
      && error.details.outcome === 'unknown',
  );
  assert.equal(browserOpened, false);
});

test('a failed browser submission keeps the durable unknown marker for the next process', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-submit-failure-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);
  const runner = runnerFor(directory, store, publishingPage({
    click: async () => {
      throw new Error('Browser closed while submitting.');
    },
  }));

  await assert.rejects(
    runner.publish(publishRequest('publish-unknown-3')),
    (error) => error instanceof ManualActionError
      && error.details.idempotency_key === 'publish-unknown-3'
      && error.details.outcome === 'unknown',
  );

  const reloaded = new StateStore(statePath);
  assert.equal(reloaded.get('publish-unknown-3'), null);
  assert.equal(reloaded.getPending('publish-unknown-3').outcome, 'unknown');

  const restarted = runnerFor(directory, reloaded, null);
  await assert.rejects(
    restarted.publish(publishRequest('publish-unknown-3')),
    (error) => error instanceof ManualActionError && error.details.outcome === 'unknown',
  );
});

test('a confirmed result replaces the pending marker and remains replayable', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-submit-success-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);
  const runner = runnerFor(directory, store, publishingPage());

  const result = await runner.publish(publishRequest('publish-completed-1'));

  assert.equal(result.status, 'published');
  const reloaded = new StateStore(statePath);
  assert.equal(reloaded.getPending('publish-completed-1'), null);
  assert.equal(reloaded.get('publish-completed-1').status, 'published');

  const replay = await runnerFor(directory, reloaded, null)
    .publish(publishRequest('publish-completed-1'));
  assert.equal(replay.status, 'published');
  assert.equal(replay.idempotent_replay, true);
});

test('simulation never records a real submit attempt', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-simulate-'));
  const store = new TrackingStateStore(path.join(directory, 'state.json'));
  const runner = runnerFor(directory, store, publishingPage());

  const result = await runner.publish(publishRequest('publish-simulated-1', 'simulate'));

  assert.equal(result.status, 'simulated');
  assert.equal(store.submitAttempts, 0);
  assert.equal(store.getPending('publish-simulated-1'), null);
});

test('baijiahao refuses a legacy completed publish result without opening the browser', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-legacy-publish-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);
  store.put('baijiahao-legacy-publish-1', {
    ok: true,
    status: 'published',
    remote_id: null,
    remote_url: null,
    remote_meta: { publish_mode: 'publish' },
  });

  let browserOpened = false;
  const runner = runnerFor(directory, new StateStore(statePath), null);
  runner.context = async () => {
    browserOpened = true;
    throw new Error('A legacy completed publish must not be resubmitted automatically.');
  };

  await assert.rejects(
    runner.publish(baijiahaoRequest('baijiahao-legacy-publish-1')),
    (error) => error instanceof ManualActionError
      && error.details.idempotency_key === 'baijiahao-legacy-publish-1'
      && error.details.outcome === 'legacy_verification_contract',
  );
  assert.equal(browserOpened, false);
});

test('baijiahao also locks legacy reviewing and draft results for manual reconciliation', async () => {
  const cases = [
    ['reviewing', 'publish'],
    ['draft', 'draft'],
  ];

  for (const [status, publishMode] of cases) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), `dianqian-runner-legacy-${status}-`));
    const statePath = path.join(directory, 'state.json');
    const key = `baijiahao-legacy-${status}-1`;
    const store = new StateStore(statePath);
    store.put(key, {
      ok: true,
      status,
      remote_id: null,
      remote_url: null,
      remote_meta: { publish_mode: publishMode },
    });

    let browserOpened = false;
    const runner = runnerFor(directory, new StateStore(statePath), null);
    runner.context = async () => {
      browserOpened = true;
      throw new Error('A legacy completed result must not be resubmitted automatically.');
    };

    await assert.rejects(
      runner.publish(baijiahaoRequest(key, publishMode)),
      (error) => error instanceof ManualActionError
        && error.details.outcome === 'legacy_verification_contract',
      status,
    );
    assert.equal(browserOpened, false, status);
  }
});

test('baijiahao safely replaces a legacy simulation result with current contract evidence', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-legacy-simulate-'));
  const statePath = path.join(directory, 'state.json');
  const store = new TrackingStateStore(statePath);
  store.put('baijiahao-legacy-simulate-1', {
    ok: true,
    status: 'simulated',
    remote_id: null,
    remote_url: null,
    remote_meta: { publish_mode: 'simulate' },
  });

  const result = await runnerFor(directory, store, publishingPage())
    .publish(baijiahaoRequest('baijiahao-legacy-simulate-1', 'simulate'));

  assert.equal(result.status, 'simulated');
  assert.equal(result.idempotent_replay, undefined);
  assert.equal(result.remote_meta.verification_contract_version, 2);
  assert.equal(store.submitAttempts, 0);
  assert.equal(store.get('baijiahao-legacy-simulate-1').remote_meta.verification_contract_version, 2);
});

test('baijiahao refuses a cached result from another publish mode', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-mode-replay-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);
  store.put('baijiahao-mode-replay-1', {
    ok: true,
    status: 'draft',
    remote_id: null,
    remote_url: null,
    remote_meta: {
      publish_mode: 'draft',
      verification_contract_version: 2,
    },
  });

  let browserOpened = false;
  const runner = runnerFor(directory, new StateStore(statePath), null);
  runner.context = async () => {
    browserOpened = true;
    throw new Error('A cached result from another publish mode must not be reused.');
  };

  await assert.rejects(
    runner.publish(baijiahaoRequest('baijiahao-mode-replay-1', 'publish')),
    (error) => error instanceof ManualActionError
      && error.details.idempotency_key === 'baijiahao-mode-replay-1'
      && error.details.outcome === 'publish_mode_mismatch',
  );
  assert.equal(browserOpened, false);
});

test('baijiahao stores cache identity and replays only the same current contract result', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-current-replay-'));
  const statePath = path.join(directory, 'state.json');
  const store = new StateStore(statePath);
  const key = 'baijiahao-current-replay-1';

  const result = await runnerFor(directory, store, publishingPage())
    .publish(baijiahaoRequest(key));
  assert.equal(result.remote_meta.verification_contract_version, 2);
  assert.equal(result.remote_meta.platform, 'baijiahao');
  assert.equal(result.remote_meta.account_id, 'default');

  const replay = await runnerFor(directory, new StateStore(statePath), null)
    .publish(baijiahaoRequest(key));
  assert.equal(replay.status, 'published');
  assert.equal(replay.idempotent_replay, true);
});

test('baijiahao refuses current cache entries for another platform or account', async () => {
  const cases = [
    ['platform', { platform: 'toutiao', account_id: 'default' }],
    ['account', { platform: 'baijiahao', account_id: 'other-account' }],
  ];

  for (const [label, identity] of cases) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), `dianqian-runner-cache-${label}-`));
    const statePath = path.join(directory, 'state.json');
    const key = `baijiahao-cache-${label}-1`;
    const store = new StateStore(statePath);
    store.put(key, {
      ok: true,
      status: 'published',
      remote_id: null,
      remote_url: null,
      remote_meta: {
        publish_mode: 'publish',
        verification_contract_version: 2,
        ...identity,
      },
    });

    let browserOpened = false;
    const runner = runnerFor(directory, new StateStore(statePath), null);
    runner.context = async () => {
      browserOpened = true;
      throw new Error('A cache identity mismatch must not open the browser.');
    };

    await assert.rejects(
      runner.publish(baijiahaoRequest(key)),
      (error) => error instanceof ManualActionError
        && error.details.outcome === 'cached_identity_mismatch',
      label,
    );
    assert.equal(browserOpened, false, label);
  }
});

test('baijiahao refuses a current cache entry with a status invalid for its mode', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-cache-status-'));
  const statePath = path.join(directory, 'state.json');
  const key = 'baijiahao-cache-status-1';
  const store = new StateStore(statePath);
  store.put(key, {
    ok: true,
    status: 'simulated',
    remote_id: null,
    remote_url: null,
    remote_meta: {
      publish_mode: 'publish',
      verification_contract_version: 2,
      platform: 'baijiahao',
      account_id: 'default',
    },
  });

  let browserOpened = false;
  const runner = runnerFor(directory, new StateStore(statePath), null);
  runner.context = async () => {
    browserOpened = true;
    throw new Error('An invalid cached status must not open the browser.');
  };

  await assert.rejects(
    runner.publish(baijiahaoRequest(key)),
    (error) => error instanceof ManualActionError
      && error.details.outcome === 'cached_status_invalid',
  );
  assert.equal(browserOpened, false);
});

test('a result and pending marker for the same key fail closed before replay', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-runner-state-conflict-'));
  const statePath = path.join(directory, 'state.json');
  const key = 'baijiahao-state-conflict-1';
  const store = new StateStore(statePath);
  store.put(key, {
    ok: true,
    status: 'published',
    remote_id: null,
    remote_url: null,
    remote_meta: {
      publish_mode: 'publish',
      verification_contract_version: 2,
      platform: 'baijiahao',
      account_id: 'default',
    },
  });
  store.state.pending[key] = {
    state: 'pending',
    outcome: 'unknown',
    platform: 'baijiahao',
    account_id: 'default',
    publish_mode: 'publish',
    stored_at: new Date().toISOString(),
  };
  store.write();

  let browserOpened = false;
  const runner = runnerFor(directory, new StateStore(statePath), null);
  runner.context = async () => {
    browserOpened = true;
    throw new Error('A conflicting state entry must not open the browser.');
  };

  await assert.rejects(
    runner.publish(baijiahaoRequest(key)),
    (error) => error instanceof ManualActionError
      && error.details.outcome === 'state_conflict',
  );
  assert.equal(browserOpened, false);
});

test('baijiahao requires the current verification contract before opening the browser', async () => {
  for (const version of ['missing', 1, '2']) {
    const label = String(version);
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), `dianqian-runner-contract-${label}-`));
    let browserOpened = false;
    const runner = runnerFor(directory, new StateStore(path.join(directory, 'state.json')), null);
    runner.context = async () => {
      browserOpened = true;
      throw new Error('An invalid verification contract must not open the browser.');
    };

    await assert.rejects(
      runner.publish(baijiahaoRequest(`baijiahao-contract-${label}`, 'publish', version)),
      (error) => error.code === 'invalid_payload' && error.status === 422,
      label,
    );
    assert.equal(browserOpened, false, label);
  }
});

test('missing or invalid publish modes are rejected before the browser opens', async () => {
  const invalidModes = [
    ['missing', undefined],
    ['empty', ''],
    ['unknown', 'preview'],
    ['not-normalized', ' publish '],
  ];

  for (const [label, publishMode] of invalidModes) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), `dianqian-runner-mode-${label}-`));
    const request = publishRequest(`publish-mode-${label}`);
    if (publishMode === undefined) {
      delete request.publish_mode;
    } else {
      request.publish_mode = publishMode;
    }
    let browserOpened = false;
    const runner = runnerFor(directory, new StateStore(path.join(directory, 'state.json')), null);
    runner.context = async () => {
      browserOpened = true;
      throw new Error('Invalid publish modes must not open the browser.');
    };

    await assert.rejects(
      runner.publish(request),
      (error) => error.code === 'invalid_payload' && error.status === 422,
      label,
    );
    assert.equal(browserOpened, false, label);
  }
});

class TrackingStateStore extends StateStore {
  submitAttempts = 0;

  markPending(idempotencyKey, details) {
    this.submitAttempts += 1;
    return super.markPending(idempotencyKey, details);
  }
}

function publishRequest(idempotencyKey, publishMode = 'publish') {
  return {
    platform: 'toutiao',
    account_id: 'default',
    idempotency_key: idempotencyKey,
    publish_mode: publishMode,
    payload: {
      article: {
        title: 'Unknown outcome guard',
        content: 'Body',
        is_ai_generated: false,
      },
      assets: { images: [] },
    },
  };
}

function baijiahaoRequest(idempotencyKey, publishMode = 'publish', verificationContractVersion = 2) {
  const request = publishRequest(idempotencyKey, publishMode);
  request.platform = 'baijiahao';
  if (verificationContractVersion === 'missing') {
    delete request.verification_contract_version;
  } else {
    request.verification_contract_version = verificationContractVersion;
  }
  return request;
}

function runnerFor(directory, store, page) {
  const runner = new BrowserRunner({
    enabled: true,
    stopPath: path.join(directory, 'STOP'),
    screenshotsDir: directory,
    operationTimeoutMs: 100,
  }, store, { write() {} });
  runner.assertPlatform = () => simulatedPlatform();
  runner.context = async () => {
    if (!page) {
      throw new Error('A guarded replay must not open the browser.');
    }
    return {};
  };
  runner.page = async () => page;
  runner.captureFailure = async () => '';
  return runner;
}

function simulatedPlatform() {
  return {
    label: 'Test platform',
    loginUrl: 'https://example.test/login',
    publishUrl: 'https://example.test/editor',
    titleSelectors: ['#title'],
    editorSelectors: ['#editor'],
    editorFormat: 'markdown',
    publishTexts: ['Publish'],
    publishControlMode: 'exact_text',
    successUrlPatterns: [/\/published$/],
  };
}

function publishingPage({ click } = {}) {
  let currentUrl = 'https://example.test/original';
  let title = '';
  let body = '';
  const candidates = (items) => ({
    count: async () => items.length,
    nth: (index) => items[index],
  });
  const empty = candidates([]);
  const titleControl = {
    isVisible: async () => true,
    fill: async (value) => { title = value; },
    evaluate: async (callback) => String(callback).includes('HTMLInputElement') ? title : title,
  };
  const editorControl = {
    isVisible: async () => true,
    fill: async (value) => { body = value; },
    evaluate: async (callback) => {
      const source = String(callback);
      if (source.includes('tagName.toLowerCase')) {
        return 'textarea';
      }
      return source.includes('querySelectorAll') ? [] : body;
    },
  };
  const publishControl = {
    isVisible: async () => true,
    evaluate: async (callback) => callback({ closest: () => null }),
    click: async () => {
      if (click) {
        await click();
      }
      currentUrl = 'https://example.test/published';
    },
  };

  return {
    url: () => currentUrl,
    goto: async (url) => { currentUrl = url; },
    bringToFront: async () => {},
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
      assert.deepEqual(options, { exact: true });
      return text === 'Publish' ? candidates([publishControl]) : empty;
    },
  };
}

function surfaceBodyLocator(parts) {
  return {
    evaluate: async (callback, selectors) => {
      const clonedParts = parts.map((part) => ({
        selector: part.selector,
        text: part.text(),
        removed: false,
      }));
      const clone = {
        querySelectorAll: (selector) => clonedParts
          .filter((part) => !part.removed && part.selector === selector)
          .map((part) => ({ remove: () => { part.removed = true; } })),
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
