import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function loadEnvFile(filePath) {
  if (!fs.existsSync(filePath)) {
    return;
  }

  const contents = fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, '');
  for (const line of contents.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }
    const separator = trimmed.indexOf('=');
    if (separator < 1) {
      continue;
    }
    const key = trimmed.slice(0, separator).trim();
    const value = trimmed.slice(separator + 1).trim().replace(/^(['"])(.*)\1$/, '$2');
    if (process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

loadEnvFile(path.join(rootDir, '.env'));

function integer(name, fallback, minimum, maximum) {
  const value = Number.parseInt(process.env[name] ?? '', 10);
  if (!Number.isFinite(value)) {
    return fallback;
  }
  return Math.min(maximum, Math.max(minimum, value));
}

function boolean(name, fallback) {
  const value = process.env[name];
  if (value === undefined) {
    return fallback;
  }
  return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
}

const host = process.env.RUNNER_HOST?.trim() || '127.0.0.1';
if (!['127.0.0.1', '::1', 'localhost', '0.0.0.0'].includes(host)) {
  throw new Error('RUNNER_HOST 只能使用回环地址或 0.0.0.0。Docker 访问本机 Runner 时使用 0.0.0.0。');
}

const token = process.env.RUNNER_TOKEN?.trim() || '';
if (token.length < 24) {
  throw new Error('RUNNER_TOKEN 至少需要 24 个字符。请先运行 start.ps1 自动生成。');
}

export const config = Object.freeze({
  rootDir,
  dataDir: path.join(rootDir, 'data'),
  profilesDir: path.join(rootDir, 'data', 'profiles'),
  screenshotsDir: path.join(rootDir, 'data', 'screenshots'),
  logsDir: path.join(rootDir, 'data', 'logs'),
  statePath: path.join(rootDir, 'data', 'state.json'),
  stopPath: path.join(rootDir, 'data', 'STOP'),
  host,
  port: integer('RUNNER_PORT', 19090, 1024, 65535),
  token,
  enabled: boolean('RUNNER_ENABLED', true),
  headless: boolean('RUNNER_HEADLESS', false),
  browser: process.env.RUNNER_BROWSER?.trim() || 'chromium',
  operationTimeoutMs: integer('RUNNER_OPERATION_TIMEOUT_MS', 180000, 30000, 240000),
  maxRequestBytes: integer('RUNNER_MAX_REQUEST_BYTES', 12 * 1024 * 1024, 1024, 25 * 1024 * 1024),
});

for (const directory of [config.dataDir, config.profilesDir, config.screenshotsDir, config.logsDir]) {
  fs.mkdirSync(directory, { recursive: true });
}
