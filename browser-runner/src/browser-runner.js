import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { publishWithBrowser, readinessControlsAreUsable, waitForReadinessState } from './automation.js';
import { ManualActionError, RunnerError } from './errors.js';
import { getPlatform, platformKeys } from './platforms.js';

export class BrowserRunner {
  constructor(config, stateStore, logger) {
    this.config = config;
    this.stateStore = stateStore;
    this.logger = logger;
    this.contexts = new Map();
    this.activeAccounts = new Set();
  }

  health(platformKey = '', accountId = '') {
    const account = platformKey && accountId ? this.accountStatus(platformKey, accountId) : null;
    return {
      ok: true,
      service: 'dianqian-geo-browser-runner',
      enabled: this.isEnabled(),
      stopped: fs.existsSync(this.config.stopPath),
      platforms: platformKeys(),
      account,
      active_sessions: this.contexts.size,
    };
  }

  async openLogin(platformKey, accountId) {
    const platform = this.assertPlatform(platformKey);
    const normalizedAccountId = sanitizeIdentifier(accountId, 'account_id');
    const context = await this.context(platformKey, normalizedAccountId);
    const page = await this.page(context);
    await page.goto(platform.loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.bringToFront();
    this.logger.write('info', 'account.login_opened', { platform: platformKey, account_id: normalizedAccountId });

    return {
      ok: true,
      status: 'login_window_opened',
      platform: platformKey,
      platform_label: platform.label,
      account_id: normalizedAccountId,
      current_url: page.url(),
    };
  }

  async readiness(platformKey, accountId) {
    this.assertEnabled();
    const normalizedPlatformKey = sanitizeIdentifier(platformKey, 'platform');
    const normalizedAccountId = sanitizeIdentifier(accountId, 'account_id');
    const platform = this.assertPlatform(normalizedPlatformKey);
    const accountKey = `${normalizedPlatformKey}:${normalizedAccountId}`;
    if (this.activeAccounts.has(accountKey)) {
      throw new RunnerError('该平台账号当前有任务正在执行，请稍后重试。', { code: 'account_busy', status: 429 });
    }

    this.activeAccounts.add(accountKey);
    this.logger.write('info', 'account.readiness_started', {
      platform: normalizedPlatformKey,
      account_id: normalizedAccountId,
    });

    try {
      const context = await this.context(normalizedPlatformKey, normalizedAccountId);
      const page = await this.page(context);
      await page.goto(platform.publishUrl, { waitUntil: 'domcontentloaded', timeout: this.config.operationTimeoutMs });
      const { pageState, controls } = await waitForReadinessState(page, platform, 15000);
      const reason = pageState.blockingText
        || (pageState.looksLikeLogin ? 'login_required' : '')
        || (!readinessControlsAreUsable(platform, controls)
          ? (!controls.title_editor_visible ? 'title_editor_not_visible' : 'body_editor_not_visible')
          : '')
        || null;
      const result = {
        ok: true,
        ready: reason === null,
        reason,
        platform: normalizedPlatformKey,
        platform_label: platform.label,
        account_id: normalizedAccountId,
        current_url: pageState.url,
        ...controls,
      };
      if (!result.ready) {
        result.screenshot = await this.captureFailure(page, normalizedPlatformKey, normalizedAccountId) || null;
      }

      this.logger.write('info', 'account.readiness_completed', {
        platform: normalizedPlatformKey,
        account_id: normalizedAccountId,
        ready: result.ready,
        reason: result.reason,
        title_editor_visible: result.title_editor_visible,
        body_editor_visible: result.body_editor_visible,
        file_input_count: result.file_input_count,
        cover_setting_visible: result.cover_setting_visible,
        screenshot: result.screenshot ?? null,
      });
      return result;
    } finally {
      this.activeAccounts.delete(accountKey);
    }
  }

  async publish(request) {
    this.assertEnabled();
    const platformKey = sanitizeIdentifier(request.platform, 'platform');
    const accountId = sanitizeIdentifier(request.account_id, 'account_id');
    const idempotencyKey = String(request.idempotency_key ?? '').trim();
    if (idempotencyKey === '' || idempotencyKey.length > 255) {
      throw new RunnerError('idempotency_key 不能为空且不能超过 255 个字符。', { code: 'invalid_payload', status: 422 });
    }

    const existing = this.stateStore.get(idempotencyKey);
    if (existing) {
      return { ...existing, idempotent_replay: true };
    }

    const accountKey = `${platformKey}:${accountId}`;
    if (this.activeAccounts.has(accountKey)) {
      throw new RunnerError('该平台账号已有发布任务正在执行，请稍后重试。', { code: 'account_busy', status: 429 });
    }

    const platform = this.assertPlatform(platformKey);
    this.activeAccounts.add(accountKey);
    this.logger.write('info', 'publish.started', { platform: platformKey, account_id: accountId, idempotency_key: idempotencyKey });
    let page;
    try {
      const context = await this.context(platformKey, accountId);
      page = await this.page(context);
      await page.bringToFront();
      const result = await publishWithBrowser(page, platformKey, platform, request, this.config.operationTimeoutMs);
      this.stateStore.put(idempotencyKey, result);
      this.logger.write('info', 'publish.completed', {
        platform: platformKey,
        account_id: accountId,
        idempotency_key: idempotencyKey,
        status: result.status,
        remote_id: result.remote_id,
      });
      return result;
    } catch (error) {
      const screenshot = page ? await this.captureFailure(page, platformKey, accountId) : '';
      this.logger.write('error', 'publish.failed', {
        platform: platformKey,
        account_id: accountId,
        idempotency_key: idempotencyKey,
        code: error?.code ?? 'runner_error',
        message: error instanceof Error ? error.message : String(error),
        screenshot,
      });
      if (error instanceof RunnerError) {
        error.details = { ...error.details, screenshot };
        throw error;
      }
      throw new RunnerError(error instanceof Error ? error.message : '浏览器发布失败。', { details: { screenshot } });
    } finally {
      this.activeAccounts.delete(accountKey);
    }
  }

  async update() {
    throw new ManualActionError('当前浏览器渠道仅自动发布新文章；远端编辑需要平台提供稳定的编辑入口后才能启用。');
  }

  async delete() {
    throw new ManualActionError('当前浏览器渠道不自动删除远端文章，避免页面改版时误删内容。请在对应平台后台人工删除。');
  }

  async stop() {
    fs.writeFileSync(this.config.stopPath, `${new Date().toISOString()}\n`, 'utf8');
    await this.close();
    this.logger.write('warning', 'runner.stopped');
    return this.health();
  }

  start() {
    fs.rmSync(this.config.stopPath, { force: true });
    this.logger.write('info', 'runner.started');
    return this.health();
  }

  async close() {
    await Promise.allSettled([...this.contexts.values()].map((context) => context.close()));
    this.contexts.clear();
  }

  isEnabled() {
    return this.config.enabled && !fs.existsSync(this.config.stopPath);
  }

  assertEnabled() {
    if (!this.isEnabled()) {
      throw new RunnerError('浏览器自动发布已由停止开关关闭。', { code: 'runner_stopped', status: 503 });
    }
  }

  assertPlatform(platformKey) {
    const platform = getPlatform(platformKey);
    if (!platform) {
      throw new RunnerError(`不支持的平台：${platformKey}`, { code: 'unsupported_platform', status: 422 });
    }
    return platform;
  }

  accountStatus(platformKey, accountId) {
    const platform = this.assertPlatform(platformKey);
    const normalizedAccountId = sanitizeIdentifier(accountId, 'account_id');
    const accountKey = `${platformKey}:${normalizedAccountId}`;
    return {
      platform: platformKey,
      platform_label: platform.label,
      account_id: normalizedAccountId,
      profile_exists: fs.existsSync(this.profilePath(platformKey, normalizedAccountId)),
      browser_open: this.contexts.has(accountKey),
      busy: this.activeAccounts.has(accountKey),
      login_status: this.contexts.has(accountKey) ? 'browser_open' : 'unknown',
    };
  }

  async context(platformKey, accountId) {
    const accountKey = `${platformKey}:${accountId}`;
    const existing = this.contexts.get(accountKey);
    if (existing) {
      return existing;
    }

    if (this.config.browser !== 'chromium') {
      throw new RunnerError('当前版本仅支持 RUNNER_BROWSER=chromium。', { code: 'invalid_configuration' });
    }

    const context = await chromium.launchPersistentContext(this.profilePath(platformKey, accountId), {
      headless: this.config.headless,
      locale: 'zh-CN',
      viewport: this.config.headless ? { width: 1440, height: 1000 } : null,
      args: this.config.headless ? [] : ['--start-maximized'],
    });
    context.setDefaultTimeout(15000);
    context.setDefaultNavigationTimeout(this.config.operationTimeoutMs);
    context.on('close', () => this.contexts.delete(accountKey));
    this.contexts.set(accountKey, context);
    return context;
  }

  profilePath(platformKey, accountId) {
    return path.join(this.config.profilesDir, platformKey, accountId);
  }

  async page(context) {
    return context.pages()[0] ?? context.newPage();
  }

  async captureFailure(page, platformKey, accountId) {
    const filename = `${platformKey}-${accountId}-${new Date().toISOString().replace(/[:.]/g, '-')}.png`;
    const filePath = path.join(this.config.screenshotsDir, filename);
    try {
      await page.screenshot({ path: filePath, fullPage: true, timeout: 10000 });
      return filePath;
    } catch {
      return '';
    }
  }
}

export function sanitizeIdentifier(value, field) {
  const normalized = String(value ?? '').trim();
  if (!/^[A-Za-z0-9_-]{1,80}$/.test(normalized)) {
    throw new RunnerError(`${field} 只能包含字母、数字、下划线和短横线。`, { code: 'invalid_payload', status: 422 });
  }
  return normalized;
}
