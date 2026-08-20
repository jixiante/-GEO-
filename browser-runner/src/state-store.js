import fs from 'node:fs';
import path from 'node:path';

export class StateStore {
  constructor(filePath) {
    this.filePath = filePath;
    this.state = this.read();
  }

  get(idempotencyKey) {
    return this.state.results[idempotencyKey] ?? null;
  }

  getPending(idempotencyKey) {
    return this.state.pending[idempotencyKey] ?? null;
  }

  markPending(idempotencyKey, details = {}) {
    if (this.get(idempotencyKey) || this.getPending(idempotencyKey)) {
      return false;
    }
    this.state.pending[idempotencyKey] = {
      ...details,
      state: 'pending',
      outcome: 'unknown',
      stored_at: new Date().toISOString(),
    };
    this.write();
    return true;
  }

  put(idempotencyKey, result) {
    this.state.results[idempotencyKey] = {
      ...result,
      stored_at: new Date().toISOString(),
    };
    delete this.state.pending[idempotencyKey];
    this.prune();
    this.write();
  }

  read() {
    let contents;
    try {
      contents = fs.readFileSync(this.filePath, 'utf8');
    } catch (error) {
      if (error?.code === 'ENOENT') {
        return { version: 2, results: {}, pending: {} };
      }
      throw error;
    }

    const parsed = JSON.parse(contents);
    if (parsed?.version !== 2) {
      throw new Error('Browser Runner state file has an unsupported version.');
    }
    if (!isStateMap(parsed?.results)) {
      throw new Error('Browser Runner state file has an invalid results map.');
    }
    if (!isStateMap(parsed?.pending)) {
      throw new Error('Browser Runner state file has an invalid pending map.');
    }
    return {
      version: 2,
      results: parsed.results,
      pending: parsed.pending,
    };
  }

  write() {
    fs.mkdirSync(path.dirname(this.filePath), { recursive: true });
    const temporaryPath = `${this.filePath}.${process.pid}.tmp`;
    fs.writeFileSync(temporaryPath, `${JSON.stringify(this.state, null, 2)}\n`, 'utf8');
    fs.renameSync(temporaryPath, this.filePath);
  }

  prune() {
    const entries = Object.entries(this.state.results);
    if (entries.length <= 2000) {
      return;
    }
    entries
      .sort((left, right) => String(left[1]?.stored_at ?? '').localeCompare(String(right[1]?.stored_at ?? '')))
      .slice(0, entries.length - 2000)
      .forEach(([key]) => delete this.state.results[key]);
  }
}

function isStateMap(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
