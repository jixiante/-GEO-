import assert from 'node:assert/strict';
import test from 'node:test';
import { createRoutes } from '../src/server.js';

test('v2 publish reuses the publish handler without opening a browser', async () => {
  const body = {
    platform: 'baijiahao',
    account_id: 'company_main',
    contract: 2,
  };
  const publishRequests = [];
  const runner = {
    publish: async (request) => {
      publishRequests.push(request);
      return { ok: true, handled: 'publish' };
    },
  };
  const routes = createRoutes(runner, body);

  assert.deepEqual(await routes['POST /v1/publish'](), { ok: true, handled: 'publish' });
  assert.deepEqual(await routes['POST /v2/publish'](), { ok: true, handled: 'publish' });
  assert.deepEqual(publishRequests, [body, body]);
});
