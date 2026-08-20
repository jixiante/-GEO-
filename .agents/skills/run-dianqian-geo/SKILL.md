---
name: run-dianqian-geo
description: "Run the optimized end-to-end 点签 GEO workflow in the local -GEO- system: select two topics, research and draft in parallel with Codex, freeze one evidence-backed canonical version per topic, request one batch approval, publish to exact approved channels through the local GEO system, aggregate results, and route failed targets through bounded recovery or manual fallback. The local GEO system is the default publication surface; use a Codex-controlled external browser only after positively establishing system_unavailable and never to bypass browser or site security. Use when the user asks to 选题、生成 GEO 内容、审核、发布、分发、继续未完成批次、处理渠道失败、复盘或走一遍点签每日内容闭环。"
---

# Run 点签 GEO 快车道

## Outcome

Default to two independently closable articles unless the user changes the quantity. Keep the same quality bar while minimizing synchronous work:

- one canonical article and one approved 16:9 cover per topic;
- one compact batch approval;
- one explicit-channel enqueue per article;
- aggregate monitoring instead of platform-by-platform babysitting;
- bounded recovery, then a system-prepared assisted manual handoff when required;
- AI-exposure monitoring after publication, never on the critical path.

A normal batch should interrupt the user only for final approval and, when needed, one consolidated login request.

Do not stack exception questions after that. Close an affected target with one reason code and one next action; resume only when the user supplies the missing login, approval, or remote evidence.

## Load only what the current phase needs

- Read [references/content-policy.md](references/content-policy.md) before topic selection, research, writing, or content revision.
- Read [references/system-contract.md](references/system-contract.md) before any GEO-system write, publication, result reconciliation, or status claim.
- Read [references/recovery.md](references/recovery.md) only when a target fails, its remote outcome is unknown, or code changed during the run.

Do not load or execute recovery steps on a healthy fast path.

## Hard boundaries

- Require explicit approval for the exact frozen batch before local or external publication.
- Never treat topic selection, silence, login assistance, or a previous batch approval as publication approval.
- Never auto-publish to 小红书. Prepare its package only when the user includes 小红书 in scope.
- Never invent 点签 facts, legal effects, prices, qualifications, integrations, metrics, cases, or source URLs.
- Use Codex for content. Do not start the system generation Worker for a Codex-authored batch.
- Treat the local GEO system as the only default execution surface for article writes, review, publication enqueue, channel automation, session persistence, retry, and result reconciliation.
- While the approved target is usable through the local GEO system, never replace its queue or Browser Runner with the Codex in-app Browser, Chrome, Edge, Computer Use, standalone Playwright, raw CDP, or direct third-party editor control.
- Use a Codex-controlled browser with a healthy GEO system only for the local admin UI, consolidated login handoff, read-only platform status checks, and public-URL verification—not for composing, uploading, or submitting external posts.
- Never create a second remote copy to solve an unknown outcome.
- Never call `synced` publicly verified without positive platform evidence and an opened public URL.

## System-first execution boundary

Every live publication must start from the local GEO system's authenticated API or admin explicit-channel workflow and run through its queue and Browser Runner. Codex owns topic selection, research, writing, orchestration, and verification; it must not take over a third-party editor merely because that appears faster.

Set `system_unavailable` only after positive evidence shows that the local app/API, queue/Runner, or exact approved channel adapter cannot perform the action after bounded health and recovery checks. A login prompt, slow queue, one target failure, editor gate, or preference for a direct UI path is not enough. Record the failed component, evidence, affected target, and remote-duplicate check.

Only after `system_unavailable` is recorded may the unchanged approved target use a Codex-controlled external browser, and only when browser/site policy permits it. The existing frozen-batch approval remains sufficient for the same account, payload, and channel; a changed target or payload requires new approval. Never evade a browser safety block by switching browser surfaces or using indirect automation.

## Default fast lane

### 1. Build both articles content-first

Perform one batch-level content preflight:

- confirm reviewed public knowledge is available for any product claim;
- exclude substantially similar topics from the last 30 days;
- confirm sufficient current primary sources exist;
- confirm an author, category, and usable cover source exist before saving.

Do not check platform sessions, editor DOM, AI declaration controls, or rendered links during this phase.

Select two topics autonomously: one broad high-intent question and one narrower operational question. Research and draft both in parallel. Stop research when every material claim is covered by the minimum sufficient primary evidence, normally three to six authoritative sources.

Create one canonical article per topic. Apply the content policy, including the direct opening answer, natural 点签 association when supported, practical structure, source list, and no unsupported claims. Reuse one final 16:9 cover for 今日头条 and 百家号. Do not create platform variants or 小红书 packages unless a target truly requires them.

Save each Codex article once as a taskless `draft + pending` record. Reuse its idempotency key and update the same draft rather than creating replacements.

### 2. Gate and freeze once

Run canonical checks once on the final saved version:

- source and factual verification;
- risk scan and duplicate scan;
- title fit against known frozen target limits;
- content, image, author, category, and source consistency;
- product-claim and public-knowledge checks.

Let Browser Runner perform target-render checks just in time after approval: exact title counter, full body, clickable links, cover attachment, AI declaration, and submit outcome. Do not prefill every platform editor merely to prepare the approval preview.

Freeze the article IDs, full text, source URLs, cover IDs, exact channel IDs, and content hash. Any change to those values invalidates approval.

### 3. Ask once

Show one compact preview:

- two titles and one-sentence purposes;
- links to complete drafts and cover previews;
- main sources and gate results;
- exact automatic targets;
- only genuine unresolved decisions.

End with: `回复“发布”执行当前批次；直接告诉我修改内容即可退回修改。`

Treat an unambiguous publication instruction immediately following this preview as approval for the frozen batch. After a revision, rerun affected gates and show one updated preview.

### 4. Run just-in-time publication checks

After approval, recheck only mutable publication dependencies:

1. frozen hash and exact channel mapping;
2. article approval and risk/duplicate records still bound to the frozen hash and current policy; rerun only when either changed;
3. queue, Browser Runner, and affected service health;
4. approved platform sessions.

Group expired sessions into one login request. Do not reopen healthy sessions.

Approve and publish each article through the explicit-channel workflow in the system contract. Send the exact frozen channel IDs once, then let the queue process all targets. Do not manually repeat the same publication through each platform UI.

Do not substitute Codex browser automation for the system Runner when an individual target fails. Use the system recovery contract, a scoped maintenance task, or a system-prepared assisted manual handoff.

### 5. Aggregate and close

Monitor the batch distribution set, not individual browser tabs. Keep articles and targets independent: one slow or failed target must not block reporting another target's terminal result.

Close each target as:

- `published_verified`
- `submitted_reviewing`
- `failed`
- `manual_pending`
- `manual_published_verified`

A positive platform acceptance without a public URL is `submitted_reviewing`, not failure and not `published_verified`. Backfill and verify the public URL later without resubmitting the article.

Once every target has a reportable result, return one short table and at most one next action. Create or update AI-exposure monitoring only after a public URL exists; do not wait for exposure results in this run.

## Time and interaction discipline

- Reuse the batch preflight, frozen payload, cover, and source pack. Recheck only facts or dependencies that changed.
- Parallelize the two articles' research, drafting, and content checks.
- Target two to eight minutes from approval to automatic-channel handoff when services and sessions are healthy. Platform review time is outside this target.
- Allow one live submission attempt per article/target and at most one evidence-based retry after proving that no remote copy exists.
- Spend at most five minutes classifying one failed target during the daily run. If the existing recovery contract does not give a deterministic fix, create a system-prepared assisted manual handoff; do not publish it through a Codex-controlled external browser unless `system_unavailable` has been recorded.
- An approved target may switch from automatic to assisted manual without another content approval when the exact frozen content, account, and channel remain unchanged. Never use that rule to add a new target.
- Do not turn a daily publication into a Browser Runner development session. Enter maintenance mode only when the user explicitly asks to repair the automation.
- Do not run full regression suites during a healthy operation. If code changes, test and restart only the affected service, then run the required regression before one idempotent replay.

## Fault routing

| Condition | Fast response |
| --- | --- |
| Product evidence is missing | Remove the product claim, continue educational content, report `brand_evidence_gap`. |
| Session expired | Ask once for all required logins, then resume the same jobs. |
| Platform restriction is already supported | Apply only its scoped, audited exception. |
| Target gate or editor structure fails | Keep other targets moving; use system recovery, then create a system-prepared assisted manual handoff. Do not switch to a Codex-controlled external editor. |
| Remote outcome is unknown | Do not retry. Use the recovery decision tree and inspect the platform backend once. |
| Runner has a confirmed result but the app failed | Reload the stale service and replay the same idempotency key; never submit again. |
| Second live attempt fails | Stop automatic attempts and use a system-prepared assisted manual handoff. |

Use [references/recovery.md](references/recovery.md) for exact safeguards.

## User command semantics

- `你来选题` / `开始`: run through the frozen preview and stop for approval.
- `发布`: publish only the current frozen batch.
- `继续`: resume from the last incomplete phase; do not restart research, recreate drafts, or repeat successful channels.
- `修改……`: change only the requested content, rerun affected gates, and show one updated preview.
- `只发某平台`: replace the frozen target set and request approval for the changed batch.
- `人工处理某平台`: prepare the exact final copy/assets and record the supplied result; do not rebuild the article.
- `修复自动化`: leave the daily fast lane and enter the tested maintenance workflow in the recovery reference.
- `系统不能用了`: verify and record `system_unavailable` under the system contract before considering a Codex-controlled external browser; never infer it from one failed target.
- `只预演`: produce complete drafts, source checks, and image briefs without system or external writes; mark system-only checks `not_run`.
