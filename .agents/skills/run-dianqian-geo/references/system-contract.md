# GEO 系统执行约定

## Runtime

Re-check these defaults before use:

- Project: repository root containing `artisan`
- Admin: `http://localhost:18080/geo_admin`
- REST base: `http://localhost:18080/api/v1`
- Browser Runner: `<project-root>/browser-runner`
- Runner health: `http://127.0.0.1:19090/health`

The local stack contains Laravel, PostgreSQL/pgvector, Redis, a scheduler, a queue Worker, and a visible Playwright Browser Runner.

Never expose API keys, Runner tokens, cookies, passwords, or decrypted channel secrets in chat or logs.

## Required execution surface

The local GEO system is the authoritative publication surface. Start every live article write, review, explicit-channel enqueue, platform submission, retry, and reconciliation through its API/admin workflow, queue, and Browser Runner.

Codex may create content, operate the local admin UI, coordinate login handoff, read platform status, and verify public URLs. While the system can perform the approved target, Codex must not use its own in-app Browser, Chrome/Edge control, Computer Use, standalone Playwright, raw CDP, or direct third-party editor control to compose, upload, or submit the post.

Record `system_unavailable` only when bounded checks positively establish that the local app/API, queue/Runner, or exact approved channel adapter cannot perform the requested action. Record the failed component, evidence, affected target, Runner state, and remote-duplicate check. A login prompt, slow queue, one distribution failure, or a convenient direct UI path does not qualify.

After `system_unavailable` is recorded, a Codex-controlled external browser is an exceptional fallback for the unchanged approved account, payload, and channel only when browser/site policy permits it. Never switch browser surfaces or use indirect automation to evade a browser safety block. Otherwise create a system-prepared assisted manual handoff.

## Fast execution contract

Perform checks at the latest safe point:

- Content phase: knowledge, sources, recent-topic duplication, author, category, cover source.
- Freeze phase: risk, duplicate, content hash, image and exact channel IDs.
- Post-approval JIT phase: queue, Runner, sessions, platform title counter, rendered content, links, cover, AI declaration, submit outcome.

Cache batch-level results. Do not repeat embedding, knowledge, source, risk, duplicate, or cover checks for the unchanged frozen payload.

Do not open platform login windows during drafting. After approval, combine expired sessions into one user request.

## Codex article write route

Use Codex for topic selection, research, writing, and revision.

Create Codex articles as taskless `draft + pending` records with an author and category. Use the v1 article API with a stable idempotency key. Update the same draft for revisions.

Do not create a placeholder task, chat model, prompt, or title-library entry for routing. Do not enqueue the generation Worker.

After exact batch approval:

1. Recalculate the frozen hash.
2. Approve the frozen article through the v1 review endpoint.
3. Publish it through `POST /api/v1/articles/{id}/publish` with the exact frozen `distribution_channel_ids`.
4. Reuse the same idempotency key for the same method, resource, and body.

REST idempotency binds the key to the token, method, resource path, and complete request body. If the article version or channel set changes, create a new frozen batch key; do not reuse the old API key with a different body.

When explicit channel IDs are present, the endpoint requires unique positive integers, accepts at most 20 channels, validates every channel before changing article state, and enqueues them in request order even when `task_id` is null. When the field is absent, the endpoint preserves local-only behavior.

Never replace the frozen IDs with “all active channels”.

Resolve each target from the current catalog plus the authenticated channel detail: match the exact active platform slug and intended account, require one unique ID, then freeze that ID. Stop on zero or multiple matches; do not guess from an old batch.

If an already-published article is missing a distribution record, the authenticated channel-detail page provides a one-article explicit enqueue action. Use it only when no publish/update distribution exists for that article/channel. Do not use it to duplicate or overwrite an existing remote copy.

## APIs and status reads

Available REST operations include:

- `GET /api/v1/catalog`
- `GET|POST /api/v1/articles`
- `GET|PATCH /api/v1/articles/{id}`
- `POST /api/v1/articles/{id}/review`
- `POST /api/v1/articles/{id}/publish`
- task and material-library CRUD

The REST API does not expose complete channel health, distribution-result reads, or verified remote state. Resolve channel IDs, distribution rows, and remote evidence through the authenticated admin UI and project services. Do not invent endpoints.

The article API does not attach final images or independent evidence records. Confirm them in the admin UI or saved assets before approval.

## Embedding and knowledge

Require a non-empty test vector before treating retrieval as healthy. Run this once per configuration change, not once per article.

When using Alibaba Cloud Model Studio, verify the current official model ID and endpoint. The intended model at the time of this contract is `text-embedding-v4`; do not substitute `qwen3.7-text-embedding`.

If a local proxy resolves DashScope into `198.18.0.0/15`, fix proxy/DNS. Do not weaken SSRF protection or allowlist the benchmark range.

The native DOCX importer flattens Word text runs and ignores embedded images. Treat the original knowledge DOCX files as source inventory. Convert reviewed material into structured public entries and verify chunking with real retrieval questions.

Only reviewed `public_faq` and explicitly approved `customer_help` material may support public product claims.

## Frozen channel policy

Automatic targets:

- `toutiao`
- `baijiahao`
- `zhihu`
- `sohu`

Manual target:

- `xiaohongshu`

The Runner contains Xiaohongshu code, but this workflow must not call it. Prepare a manual package only when requested.

Generate and approve one 16:9 cover compatible with 今日头条 and 百家号. Both results require positive `cover_verification` for that frozen image.

The Runner must verify before each submit:

- exact title and platform limit;
- full body integrity;
- every expected HTTP(S) link, except the scoped Sohu exception;
- required AI declaration;
- required cover;
- positive submit or review evidence.

For 百家号图文, explicitly verify `自动生成视频` and `自动生成播客` are both off before submit unless the frozen batch separately approved those derivatives. Treat a default or persisted checkmark as platform state, not publication intent; if it cannot be cleared and verified, stop that target before submit.

Do not perform these editor checks twice. The JIT Runner pass is authoritative.

### Sohu exception

Sohu may strip external anchors while retaining source names. Do not enable a channel-wide bypass.

Standing user instruction recorded 2026-08-11: for the configured `dianqian_main` Sohu account, the user authorizes this fallback for future approved frozen articles without another chat prompt when the only platform-side change is removal of clickable external anchors and the exact source names remain. Materialize the instruction separately for every affected distribution through the audited admin retry action, bound to that distribution ID, the current outgoing payload hash, the authenticated approver, and the approval time. This standing instruction does not approve changes to the title, body text, source names, cover, AI declaration, account, or target channel.

Allow the fallback only when one failed Sohu distribution has an explicit `plain_source_names_approval` bound to:

- that distribution ID;
- the unchanged outgoing payload hash;
- a recorded approver and approval time.

Runner must still verify title, full body, AI declaration, submit outcome, and every expected source name. Preserve missing-link facts in the audit result.

If the guarded client path cannot positively verify the exact prepared outgoing hash against that approval, do not use the automatic exception. Route the target to assisted manual publication or a separate maintenance task.

A unique visible exact `已发布` notice inside the configured Sohu message container means accepted for review. Record `reviewing`; generic page text, hidden nodes, counters, or stale notices are not evidence.

## Process freshness

Long-running services may keep old code in memory.

Before enabling a Baijiahao live target, the authenticated Runner health response must report `verification_contract_version: 2`. Baijiahao publication must use `POST /v2/publish` with `verification_contract_version=2`; a 404, 422, or missing version means the PHP queue and Runner are on mixed contracts. Stop that target, refresh the affected service, and never fall back to `/v1/publish` or direct third-party browser control.

Runner state is fail-closed. Only a genuinely missing state file may initialize an empty version-2 store. Malformed JSON, an unreadable existing path, an unsupported version, or invalid `results` / `pending` maps must stop Runner startup and require maintenance; never treat them as an empty history.

- After `browser-runner/src/**` or Runner configuration changes, restart Browser Runner and check health.
- After Job, Publisher, Orchestrator, or other queue-side PHP changes, restart the queue Worker/container.
- After controller or route changes, reload the affected Web app process when hot reload is not proven.

Before restarting queue or Runner, confirm no distribution is `sending`. Restart only the affected service.

After a code fix, run the targeted regression and the required affected suite. Then perform one replay with the original idempotency key. Do not use a new key to “test” a live publication.

## Idempotency and remote outcomes

The Runner stores:

- a confirmed result after positive evidence;
- `pending + outcome=unknown` after a submit attempt whose result is not known.

Keep the canonical frozen batch hash separate from the distribution row's `payload_hash`. The current row hash is a logical deduplication fingerprint and may differ from the final prepared Runner payload after channel-specific transformations. Do not use the row hash alone as proof that an exception is still bound to the exact outgoing content.

For a confirmed result, the same idempotency key must return an idempotent replay without opening the browser or clicking publish.

The current Runner key is derived from article, channel, action, and `v1`; it is not bound to the payload hash. Reuse it only for the exact unchanged frozen payload. Do not replay a completed Runner key after editing content.

For `pending unknown`, never retry until the platform backend has been checked. Follow the recovery reference loaded directly from `SKILL.md`.

Do not infer public availability from a primary database status:

| Evidence | Workflow label |
| --- | --- |
| Positive platform result plus opened public URL | `published_verified` |
| Accepted/reviewing result without verified public URL | `submitted_reviewing` |
| Error or bounded retry exhausted | `failed` |
| Prepared Xiaohongshu package | `manual_pending` |
| User-posted manual item plus opened public URL | `manual_published_verified` |

`synced` means the application accepted a credible channel result. Read `remote_meta.runner_status`, evidence fields, and `remote_url`.

When the platform has already accepted a submission but the application row is still failed and no public URL exists, use the local admin action `补记平台已接收` instead of retrying publication. This audited reconciliation records `synced + reviewing` and leaves `remote_url` null. A Toutiao PGC preview or management URL belongs only in `remote_meta`; it is not a public URL and must not create an AI-exposure source. For Sohu, require the configured unique visible exact `已发布` evidence. Never use this action to manufacture success without platform evidence.

For 百家号, an exact `发布成功` result with complete title/body/link/cover/AI evidence may be credible while the current URL is still `builder/rc/clue` and no public URL exists. Store the result and report `submitted_reviewing`; do not resubmit to obtain a URL.

The manual URL confirmation validates URL shape but may not visit the page. Open the page separately before using `published_verified`.

## Aggregate monitoring

After enqueuing the frozen target set:

- poll the distribution set as a group;
- do not keep navigating platform tabs;
- leave successful targets untouched;
- stop waiting once every target has a reportable result;
- exclude platform review and search/AI indexing time from the synchronous batch.

At least one public URL is enough to create or update the existing AI-exposure monitor. Do not wait for its first measurement in the publication turn.

## Failure handling

- Make one live submission attempt per article/target.
- Resume once after a consolidated login intervention.
- Retry a submitted target only after positive evidence that no remote copy exists.
- Use the same payload and idempotency key.
- After a second live failure or five minutes without deterministic classification, route that target to assisted manual publication.
- Treat assisted manual publication as a package and user handoff produced from the frozen system record, not as permission for Codex to replace the system Runner with direct third-party browser control.
- Keep the other article/channel results moving.
- Do not modify the frozen payload or target set under an existing approval.
- Treat Browser Runner `update` and `delete` as manual operations; the current Runner only automates new publication.
- Do not rely on the admin retry button to validate the frozen hash. Retry only a `failed` record whose content hash is unchanged.

Follow the recovery reference for the exact decision tree. Treat automation repair as a separate maintenance mode, not part of the daily fast lane.
