# 点签 GEO 分发快速恢复

仅在渠道失败、远端结果未知或本次运行修改了代码时读取本文件。日常快车道不得为了“更保险”提前执行这些步骤。

## 先分类，不要先重试

一次读取并保存：

- 文章 ID、分发记录 ID、渠道和幂等键；
- 应用状态、`remote_meta.runner_status`、错误信息和尝试次数；
- Runner 对应的 `publish.started`、`publish.submit_attempted`、`publish.completed` 或 `publish.failed`；
- 失败截图或明确的平台结果证据；
- Runner state 中该幂等键是 confirmed result、pending unknown 还是 absent。

不得仅凭 `submit_attempted` 断言平台收到了发布。该事件发生在实际点击之前。

重试预算中的一次 `live attempt` 从 Runner 写入 durable pending、准备操作提交控件时开始；无论最终是否确认点击成功，都消耗一次预算。发生在 durable pending 之前的纯前置门禁失败不计入 live attempt。

## 决策表

| 证据 | 处理 |
| --- | --- |
| 未发生 submit attempt | 修复明确的系统前置门禁，或生成系统辅助人工交接；可继续使用原幂等键。不得直接切换到 Codex 外部浏览器发布。 |
| Runner 已有 confirmed result | 不再操作平台。修复应用/队列读取问题后回放同一幂等键。 |
| confirmed result 与平台后台“无稿”冲突 | 不清 confirmed、不重发；保存冲突证据，转人工核账或独立维修。 |
| Runner 为 pending unknown | 先在平台后台按账号、标题和时间核对一次；核对前禁止重试。 |
| 平台后台存在对应稿件 | 保留 unknown 防重复锁；按真实状态做人工协调或补录，不再发布。 |
| 用户明确确认平台后台不存在稿件 | 只允许对该幂等键执行一次受控解锁，然后重试一次。 |
| 第二次 live attempt 仍失败 | 停止自动尝试，转 assisted manual。 |

## 受控解锁 unknown

只有同时满足以下条件才能解锁：

1. 用户刚刚核对了正确平台账号；
2. 标题和提交时间范围内没有对应草稿、审核稿或已发布稿；
3. Runner 已停止，不会并发写 state；
4. 已完整备份 state 文件；
5. 只移除目标幂等键的 pending 记录；
6. 其他 pending 和 confirmed results 原样保留；
7. JSON 结构校验通过；
8. Runner 重启并通过 health；
9. 继续使用同一幂等键，且只重试一次。

优先使用受审计的 reconcile/unlock 能力。若系统没有该能力，手工维护只能在明确的自动化维修授权下进行；日常快车道直接转人工发布。

## 服务代码新鲜度

常驻进程不会自动加载所有代码变化。修改后只重启受影响的服务：

| 改动 | 必须刷新 |
| --- | --- |
| `browser-runner/src/**`、Runner 平台规则或环境配置 | Browser Runner |
| 队列 Job、Publisher、Orchestrator 或其他 queue-side PHP | `queue` 容器/Worker |
| 控制器、路由或常驻 Web 进程加载的 PHP | 受影响的 Web app 服务 |
| 仅 Blade、翻译或非缓存静态内容 | 先验证热加载；必要时再刷新 app |

重启前确认没有 `sending` 分发任务。重启后先做 health，再进行一次幂等回放。

如果 Runner 已保存 confirmed result，而旧 queue 把应用记录标成失败：

1. 不清除 confirmed result；
2. 重启 queue；
3. 将原分发记录重新入队；
4. 使用原幂等键；
5. 确认 Runner 请求是快速 replay，没有新的 `submit_attempted`；
6. 验证应用记录变成 `synced` 并保存原始证据。

## 平台已知分支

### 百家号

- 发布控件必须精确匹配 `发布`，不能用包含匹配命中 `定时发布`。
- `发布成功` 加完整标题、正文、链接、封面和 AI 声明证据，可以确认平台已接收。
- 成功后停留在 `builder/rc/clue` 且暂时没有公开 URL 时，记录为 `submitted_reviewing`；不得为了取得 URL 再发一次。

### 搜狐号

- 外链被平台明确剥离时，仅允许使用绑定到单个分发 ID 和冻结 payload hash 的 `plain_source_names_approval`。
- 没有现成的单分发批准时，默认记为 `sohu_exception_required` 并转 assisted manual；不要在本轮连续追问批准。
- 只把配置消息容器内唯一可见的 `已发布` 作为已接收/审核中证据。页面计数、隐藏文本或普通正文中的“已发布”无效。
- 成功接受但没有公开 URL 时记录为 `submitted_reviewing`。

### 今日头条与知乎

- 继续要求标题、完整正文、链接、AI 声明以及目标所需封面证据。
- 没有公开 URL 时按 Runner 真实状态报告，不把 `synced` 自动升级为 `published_verified`。

## 日常运行的停止条件

满足任一条件就停止该渠道自动化，但不阻塞其他渠道：

- 第二次 live submission 失败；
- 五分钟内无法从既有日志、截图和状态得到确定分类；
- 需要猜测 DOM、扩大点击范围或绕过平台安全措施；
- 必须修改正文、来源、封面或目标渠道，导致原批准失效；
- 需要新凭据、法律判断或平台资质。

输出一个原因码和一个动作：

- `auth_failed` → 用户登录后恢复原任务；
- `target_gate_failed` → assisted manual；
- `sohu_exception_required` → 等待单分发批准；
- `remote_unverified` → 仅核对后台，不重试；
- `automation_maintenance_required` → 当天转人工，另开维修流程。
- `system_unavailable` → 仅在系统约定要求的组件证据和防重复检查齐全后记录；此后才可评估 Codex 浏览器兜底，且不得绕过站点安全策略。

## Assisted manual 交接

Assisted manual 是由本地 GEO 系统冻结记录生成的人工交接，不是让 Codex 绕过系统直接控制第三方编辑器。只要系统仍能执行目标，就继续走系统；只有已按系统约定记录 `system_unavailable`，且浏览器/站点策略允许时，才可考虑 Codex 浏览器兜底。

转人工时不要重写文章或重新配图。一次性交付：

- 精确平台、账号、标题和冻结正文；
- 已批准封面、来源名与来源 URL；
- 必填 AI 声明和平台发布检查项；
- 原文章 ID、分发记录 ID、冻结 hash、已消耗的 live attempts 和不得重复发布的说明。

若远端状态仍是 unknown，只能先核对后台，不能同时人工再发。人工发布完成后，记录用户提供的公开 URL，并实际打开核验标题与可访问性；通过后才标记 `manual_published_verified`，否则保持 `manual_pending` 或真实审核状态。
