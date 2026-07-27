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

  put(idempotencyKey, result) {
    this.state.results[idempotencyKey] = {
      ...result,
      stored_at: new Date().toISOString(),
    };
    this.prune();
    this.write();
  }

  read() {
    try {
      const parsed = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
      return {
        version: 1,
        results: parsed && typeof parsed.results === 'object' ? parsed.results : {},
      };
    } catch {
      return { version: 1, results: {} };
    }
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
