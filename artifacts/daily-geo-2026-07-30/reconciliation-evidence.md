# DQ-GEO-20260730-B02 异常核对记录

记录时间：2026-07-30 16:51（Asia/Shanghai）

## 今日头条 distribution 9

- 账号：`dianqian_main`
- 标题：电子合同SaaS、OpenAPI和私有化怎么选？看这5个条件
- 2026-07-30 16:45 人工提交；标题、冻结正文、5 个批准来源 URL、指定封面和“引用AI”均在提交前核对通过。
- 头条编辑器剥离富文本锚点，因此将 5 个批准 URL 作为完整可见 URL 文本保留，未删除来源。
- 头条内容管理先显示“审核中”，随后显示“已发布”。
- 公开地址：https://www.toutiao.com/article/7668238399509168674/
- 公开页已打开，页面标题与冻结标题一致。
- 本轮结论：`manual_published_verified`；禁止再次提交。
- 应用回填暂未执行：当前确认器把渠道域名限定为 `mp.toutiao.com`，而真实公开页为 `www.toutiao.com/article/...`。未使用虚假地址绕过校验。

## 今日头条 distribution 13

- 账号：`dianqian_main`
- 标题：公司电子印章怎么管？申请、授权、使用和撤销的操作清单
- 2026-07-30 16:49 人工提交；标题、冻结正文、4 个批准来源 URL、指定封面和“引用AI”均在提交前核对通过。
- 头条编辑器剥离富文本锚点，因此将 4 个批准 URL 作为完整可见 URL 文本保留，未删除来源。
- 头条内容管理显示精确标题“审核中”，稿件 ID：`7668239612124365312`。
- 本轮结论：`submitted_reviewing`；禁止重复提交，待公开地址出现后再核验与回填。

## 百家号 distribution 10

- 账号：`dianqian_main`
- 标题：电子合同SaaS、OpenAPI和私有化怎么选？看这5个条件
- 第一次提交后，用户已在百家号后台核对并确认不存在对应草稿、审核稿或已发布稿。
- 用户随后明确授权“自动化维修并重试百家号一次”。
- 维修前完整备份：`browser-runner/data/state-before-baijiahao-article5-unlock-20260730T161521.json`
- 备份 SHA256：`40513DE6AC57C3C3DB55509BCCA95CDF64010F186BF594FEFF50106BAC73003A`
- 维修动作只移除了 `article-5-channel-2-publish-v1` 的旧 `pending`；其余 4 个 pending、17 个 results 和 state version 均未改变。Runner 重启后健康检查通过。
- 冻结内容 SHA256 保持为 `cf0fa53ba802607b62905332d58eb23db946729e7b0839fd4e47c7edc38b0598`，幂等键保持为 `article-5-channel-2-publish-v1`，payload SHA256 保持为 `698e175faf1ab65f29a870cc91d15d3f3adc097e421be1012aee2559fe757ac3`。
- 第二次实际提交始于 2026-07-30 16:17:36，16:18:20 返回 `manual_action_required`：提交动作已触发，但平台未返回可确认的成功状态。
- 数据库终态：`failed`，`attempt_count=2`，无 `remote_id`/`remote_url`；Runner 再次保留该幂等键的 `pending + outcome=unknown`，没有 result。
- 第二次现场证据：[百家号第二次提交现场](../../browser-runner/data/screenshots/baijiahao-dianqian_main-2026-07-30T08-18-19-944Z.png)
- 2026-07-30 17:01（Asia/Shanghai），用户再次在正确账号后台核对并明确确认没有该标题。
- 本轮结论：`manual_pending`。两次自动尝试均未形成远端稿件，重试预算已耗尽，禁止再次解锁或自动重试；允许人工使用完全相同的冻结内容发布一次，取得公开 URL 后再核验和回填。

## 搜狐 distribution 12

- 用户批准本分发保留来源名称、去掉外部 URL。
- 批准时间：2026-07-30 14:56:49+08:00
- 批准 payload hash：`dd903401e0f080a09ce3349706ed2d27d04838065f5c614e9677542e484dc399`
- Runner 提交时间：约 2026-07-30 14:56:54+08:00
- 平台后台截图显示精确标题已进入“审核中”，时间约 14:57。
- 证据：[搜狐文章一审核截图](../../browser-runner/data/screenshots/sohu-dianqian_main-2026-07-30T06-57-31-050Z.png)
- 本轮结论：`submitted_reviewing`；禁止再次提交。公开后再回填 `https://www.sohu.com/a/...`。

## 搜狐 distribution 16

- 用户批准本分发保留来源名称、去掉外部 URL。
- 批准时间：2026-07-30 14:57:13+08:00
- 批准 payload hash：`b4fd6b9eb09bd49e2ba4ecb2b33490a4154bd8a513d640121b5d0f71b6bef64c`
- Runner 提交时间：约 2026-07-30 14:57:34+08:00
- 平台后台截图显示两篇本批次文章均处于“审核中”，文章二时间约 14:58。
- 证据：[搜狐文章二审核截图](../../browser-runner/data/screenshots/sohu-dianqian_main-2026-07-30T06-58-11-347Z.png)
- 本轮结论：`submitted_reviewing`；禁止再次提交。公开后再回填 `https://www.sohu.com/a/...`。
