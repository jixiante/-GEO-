import crypto from 'node:crypto';
import http from 'node:http';
import { URL } from 'node:url';
import { BrowserRunner } from './browser-runner.js';
import { config } from './config.js';
import { RunnerError } from './errors.js';
import { Logger } from './logger.js';
import { isPrivateAddress } from './network.js';
import { StateStore } from './state-store.js';

const logger = new Logger(config.logsDir);
const stateStore = new StateStore(config.statePath);
const runner = new BrowserRunner(config, stateStore, logger);

const server = http.createServer(async (request, response) => {
  const startedAt = Date.now();
  try {
    const url = new URL(request.url ?? '/', `http://${config.host}:${config.port}`);
    if (request.method === 'GET' && url.pathname === '/health') {
      return sendJson(response, 200, { ok: true, service: 'dianqian-geo-browser-runner' });
    }

    assertPrivateClient(request);
    authenticate(request);
    if (request.method === 'GET' && url.pathname === '/v1/health') {
      return sendJson(response, 200, runner.health(url.searchParams.get('platform') ?? '', url.searchParams.get('account_id') ?? ''));
    }
    if (request.method === 'GET' && url.pathname === '/v1/accounts/readiness') {
      const result = await runner.readiness(
        url.searchParams.get('platform') ?? '',
        url.searchParams.get('account_id') ?? '',
      );
      return sendJson(response, 200, result);
    }

    const body = await readJson(request);
    const routes = {
      'POST /v1/accounts/login': () => runner.openLogin(body.platform, body.account_id),
      'POST /v1/publish': () => runner.publish(body),
      'POST /v1/update': () => runner.update(body),
      'POST /v1/delete': () => runner.delete(body),
      'POST /v1/control/stop': () => runner.stop(),
      'POST /v1/control/start': () => runner.start(),
    };
    const handler = routes[`${request.method} ${url.pathname}`];
    if (!handler) {
      throw new RunnerError('接口不存在。', { code: 'not_found', status: 404 });
    }
    const result = await handler();
    sendJson(response, 200, result);
  } catch (error) {
    const runnerError = error instanceof RunnerError
      ? error
      : new RunnerError(error instanceof Error ? error.message : '服务请求失败。');
    sendJson(response, runnerError.status, {
      ok: false,
      code: runnerError.code,
      message: runnerError.message,
      details: runnerError.details,
    });
  } finally {
    logger.write('info', 'http.request', {
      method: request.method,
      path: String(request.url ?? '').split('?')[0],
      duration_ms: Date.now() - startedAt,
      status: response.statusCode,
      remote_address: request.socket.remoteAddress ?? '',
    });
  }
});

server.listen(config.port, config.host, () => {
  logger.write('info', 'server.listening', { host: config.host, port: config.port });
  process.stdout.write(`点签浏览器发布助手已启动：http://${config.host}:${config.port}\n`);
});

async function shutdown() {
  server.close();
  await runner.close();
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

function authenticate(request) {
  const authorization = String(request.headers.authorization ?? '');
  const provided = authorization.startsWith('Bearer ') ? authorization.slice(7) : '';
  const expectedBuffer = Buffer.from(config.token);
  const providedBuffer = Buffer.from(provided);
  if (expectedBuffer.length !== providedBuffer.length || !crypto.timingSafeEqual(expectedBuffer, providedBuffer)) {
    throw new RunnerError('配对令牌无效。', { code: 'unauthorized', status: 401 });
  }
}

function assertPrivateClient(request) {
  if (!isPrivateAddress(request.socket.remoteAddress)) {
    throw new RunnerError('仅允许本机或 Docker 私网访问。', { code: 'forbidden_client', status: 403 });
  }
}

async function readJson(request) {
  const contentLength = Number.parseInt(String(request.headers['content-length'] ?? '0'), 10);
  if (contentLength > config.maxRequestBytes) {
    throw new RunnerError('请求体过大。', { code: 'payload_too_large', status: 413 });
  }

  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > config.maxRequestBytes) {
      throw new RunnerError('请求体过大。', { code: 'payload_too_large', status: 413 });
    }
    chunks.push(chunk);
  }
  if (chunks.length === 0) {
    return {};
  }
  try {
    const value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error('object required');
    }
    return value;
  } catch {
    throw new RunnerError('请求 JSON 格式无效。', { code: 'invalid_json', status: 400 });
  }
}

function sendJson(response, status, payload) {
  const body = Buffer.from(JSON.stringify(payload));
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': body.length,
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(body);
}
