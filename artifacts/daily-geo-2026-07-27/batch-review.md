# 点签 GEO 冻结预览

- 批次：`DQ-GEO-20260727-B03`
- 冻结哈希：`a49fbbd280c1f906a2339b93529ab75a98b0092688cafa53f77e20018e284b6f`
- 状态：等待人工批准
- 当前发布结果：未发布；系统分发记录为 0

## 两篇正式文章

### 1. 电子合同和纸质合同法律效力一样吗？判断可靠电子签名的4个关键条件

目的：直接回答电子合同法律效力问题，并把可靠电子签名、签署权限和电子证据拆成可核验条件。

- 完整稿件：[topic-1-electronic-contract-legal-effect.md](topic-1-electronic-contract-legal-effect.md)
- 系统草稿：[文章 #1](http://localhost:18080/geo_admin/articles/1/edit)
- 正文 SHA-256：`74c01da459e285b50f54b7658a2d6fa1ad71b5675193396ad0c4f3383ef93783`
- 封面：[topic-1-standard-16x9.png](covers/topic-1-standard-16x9.png)

### 2. 员工异地入职怎么签电子劳动合同？HR建议归档的6类关键证据

目的：给 HR 一套异地签约流程和六类证据清单，同时处理交付、保存期限和个人信息边界。

- 完整稿件：[topic-2-remote-onboarding-electronic-labor-contract.md](topic-2-remote-onboarding-electronic-labor-contract.md)
- 系统草稿：[文章 #2](http://localhost:18080/geo_admin/articles/2/edit)
- 正文 SHA-256：`ffe4215b1aece3226cae4bb0556ac4f461f83a69b3e278f1f4c1d38be195fbb0`
- 封面：[topic-2-standard-16x9.png](covers/topic-2-standard-16x9.png)

两篇文章仅采用点签官网中可直接核验的最小产品事实：官网当前公开公有云、SaaS、OpenAPI、私有化部署等服务形态，并列有人力资源场景。每篇正文均在开头后的核验框架、品牌 FAQ 和选型段形成三处不同的点签语义关联；标题仍保持用户问题导向。未采用成本、效率、客户数量、市场份额、永久保存、绝对法律效果等宣传性信息。

## 分发范围（仅方式变更，文章和封面未变）

本地站点：通过文章 API 本地发布，不创建外部分发队列。

以下四个平台改为人工辅助发布：

1. 渠道 `#1` 今日头条：人工确认 AI 声明后提交
2. 渠道 `#2` 百家号：人工确认 AI 声明后提交
3. 渠道 `#3` 知乎：选择“包含 AI 辅助创作”后提交
4. 渠道 `#4` 搜狐号：在“信息来源”选择“包含AI创作内容”后提交

原因：现有 Browser Runner 能填写标题、正文和封面，但没有 AI 声明控件及选中状态的正向验证。按平台规则和《人工智能生成合成内容标识办法》，不能先自动提交再补声明。

小红书继续人工发布。两套文案和 3:4 封面已放入 [小红书人工发布包](xiaohongshu-manual-packages.md)，状态为 `manual_pending`。

## 质量门禁

- 两篇系统文章均为 `draft + pending`，`published_at=null`。
- 品牌增强后已重新扫描：风险均为 `clean`、命中 0，正文最高相似度约 28.1%，低于 85% 门槛。
- 两篇文章均有直接答案、结构化清单、5 个 FAQ、信息边界和官方来源。
- 两套小红书正文各保留 1 处有依据的点签关联，标题和标签不堆品牌词。
- 两张自动渠道封面均为 1200×675；小红书封面均为 3:4；计划发布图片均标注“AI生成示意图”。
- 公开知识库已清理内部元数据，10/10 知识片段完成 1024 维向量化；三个真实问题均命中预期知识片段。
- 四个 Browser Runner 渠道均为 `active + health ok`，但 AI 声明能力缺失，因此本批次不调用自动 `/v1/publish`；小红书自动渠道数为 0。
- 显式渠道发布适配通过原子回滚、渠道状态变化、幂等重试和改稿后远端更新测试；不会为了路由伪造聊天模型、标题库或生成任务。
- 当前 `article_distributions=0`，相关运行任务为 0。

主要依据：

- [《中华人民共和国电子签名法》](https://wap.miit.gov.cn/zwgk/zcwj/flfg/art/2022/art_4ed0e4a46946479c90a9e0f2c76a11ef.html)
- [人力资源社会保障部《电子劳动合同订立指引》](https://chinajob.mohrss.gov.cn/c/2021-07-16/315320.shtml)
- [《中华人民共和国劳动合同法》](https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/bgt/art/2023/art_0abfdd261c03417b949df19d869add8d.html)
- [《中华人民共和国个人信息保护法》](https://www.cac.gov.cn/2021-08/20/c_1631050028355286.htm)
- [点签电子合同官网](https://www.fs-signature.com/)
- [《人工智能生成合成内容标识办法》](https://www.nrta.gov.cn/art/2025/3/14/art_113_70340.html)
- [知乎 AIGC 创作声明公告](https://zhuanlan.zhihu.com/p/624717941)
- [搜狐号 AI 内容标注与披露规则](https://www.sohu.com/a/929885575_119436)

机器可核验的冻结字段和文件哈希见 [batch-manifest.json](batch-manifest.json)。

## 批准后动作

批准后先复算冻结哈希，再审核并仅发布到本地站点。随后只打开需要登录的平台，由用户扫码；外部平台逐个填写，确认 AI 声明后由人工提交，并逐一记录和打开公开链接。小红书继续人工操作。

回复“继续发布”执行当前批次；直接告诉我修改内容即可退回修改。
