import fs from 'node:fs';
import path from 'node:path';

export class Logger {
  constructor(logsDir) {
    this.logsDir = logsDir;
  }

  write(level, event, context = {}) {
    const date = new Date();
    const filePath = path.join(this.logsDir, `${date.toISOString().slice(0, 10)}.jsonl`);
    const entry = {
      time: date.toISOString(),
      level,
      event,
      ...context,
    };
    fs.appendFileSync(filePath, `${JSON.stringify(entry)}\n`, 'utf8');
  }
}
