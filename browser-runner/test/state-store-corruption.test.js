import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { StateStore } from '../src/state-store.js';

test('an existing malformed state file fails closed', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-malformed-'));
  const statePath = path.join(directory, 'state.json');
  fs.writeFileSync(statePath, '{"version":2', 'utf8');

  assert.throws(() => new StateStore(statePath), SyntaxError);
});

test('an existing state file with a non-object results map fails closed', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-results-'));
  const statePath = path.join(directory, 'state.json');
  fs.writeFileSync(statePath, JSON.stringify({ version: 2, results: [], pending: {} }), 'utf8');

  assert.throws(() => new StateStore(statePath), /results/);
});

test('an existing state file with a non-object pending map fails closed', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-pending-'));
  const statePath = path.join(directory, 'state.json');
  fs.writeFileSync(statePath, JSON.stringify({ version: 2, results: {}, pending: null }), 'utf8');

  assert.throws(() => new StateStore(statePath), /pending/);
});

test('an existing state file with an unsupported version fails closed', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-version-'));
  const statePath = path.join(directory, 'state.json');
  fs.writeFileSync(statePath, JSON.stringify({ version: 3, results: {}, pending: {} }), 'utf8');

  assert.throws(() => new StateStore(statePath), /version/);
});

test('an existing unreadable state path fails closed', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-unreadable-'));
  const statePath = path.join(directory, 'state.json');
  fs.mkdirSync(statePath);

  assert.throws(
    () => new StateStore(statePath),
    (error) => error?.code !== undefined && error.code !== 'ENOENT',
  );
});

test('an existing version 2 state file loads its confirmed and pending entries', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-v2-'));
  const statePath = path.join(directory, 'state.json');
  const confirmed = { status: 'published', remote_id: 'remote-1', stored_at: '2026-08-03T00:00:00.000Z' };
  const pending = { state: 'pending', outcome: 'unknown', stored_at: '2026-08-03T00:01:00.000Z' };
  fs.writeFileSync(statePath, JSON.stringify({
    version: 2,
    results: { 'confirmed-key': confirmed },
    pending: { 'pending-key': pending },
  }), 'utf8');

  const store = new StateStore(statePath);

  assert.deepEqual(store.get('confirmed-key'), confirmed);
  assert.deepEqual(store.getPending('pending-key'), pending);
});

test('a missing state file initializes an empty version 2 store', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dianqian-state-missing-'));
  const statePath = path.join(directory, 'state.json');

  const store = new StateStore(statePath);

  assert.equal(store.get('missing-key'), null);
  assert.equal(store.getPending('missing-key'), null);
  assert.equal(fs.existsSync(statePath), false);
});
