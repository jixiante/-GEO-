# 点签GEO

点签GEO是一套面向企业知识资产和生成式引擎优化（GEO）的智能内容中台。系统将知识库、素材库、AI 内容生成、风险审核、本站发布、数据分析和多渠道分发连接为一条可持续运营的工作流。

## 产品定位

点签GEO解决的不是单次 AI 写作问题，而是企业内容从资料沉淀到审核发布的全过程管理：

- 将产品资料、业务文档和 FAQ 建设为可检索知识库
- 使用 Chat 模型生成结构化 Markdown 内容
- 使用 Embedding 模型完成语义切片、向量化和 RAG 检索
- 通过风险扫描和人工审核控制发布质量
- 将文章发布到本站、WordPress、点签GEO Agent 或通用 HTTP API
- 通过访问、任务和分发数据持续复盘内容效果

## 核心流程

```text
模型与提示词
    -> 知识库与素材库
    -> 内容生产任务
    -> Redis 队列
    -> AI 生成草稿
    -> 风险扫描与人工审核
    -> 本站发布
    -> 多渠道分发
```

## 主要能力

| 模块 | 能力 |
| --- | --- |
| AI 配置器 | 管理 Chat、Embedding 模型、调用地址、配额和故障转移 |
| 知识资产 | 上传资料、规则切片、向量化、证据检索和企业知识原子管理 |
| 内容素材 | 管理标题、关键词、作者、图片和正文提示词 |
| 生产任务 | 配置生成数量、草稿池、审核开关、发布节奏和分类策略 |
| 内容审核 | 草稿编辑、风险扫描、审核批准、发布和回收站管理 |
| 多站分发 | 支持点签GEO Agent、WordPress REST 和通用 HTTP API |
| 数据分析 | 展示内容、访问、任务、分发和 AI 爬虫数据 |

## 技术架构

- Laravel 12 / PHP 8.3
- PostgreSQL + pgvector
- Redis 队列与缓存
- Laravel Reverb 实时状态推送
- Vite + Tailwind CSS
- Docker Compose 本地与生产部署

Docker Compose 运行服务包括 `app`、`postgres`、`redis`、`queue`、`scheduler` 和 `reverb`。资源构建与初始化由一次性服务完成。

## 快速部署

### 环境要求

- Docker Desktop 或 Docker Engine + Compose
- 可用的 Chat 模型和 Embedding 模型 API
- 建议至少 4 GB 可用内存

### 启动

```bash
git clone https://github.com/jian-ux/-GEO-.git dianqian-geo
cd dianqian-geo
cp .env.example .env
docker compose up -d --build
```

默认访问地址：

- 前台：`http://localhost:18080/`
- 管理后台：`http://localhost:18080/geo_admin/`

首次部署后应立即设置独立管理员密码，并在后台配置实际使用的模型 API。不要将 `.env`、管理员密码或 API Key 提交到仓库。

## 首次使用

1. 在 AI 配置器中测试 Chat 和 Embedding 模型。
2. 创建作者、标题库、关键词库和图片库。
3. 上传企业资料并完成知识切片与向量化。
4. 配置正文提示词。
5. 创建一个仅生成 1-3 篇文章的测试任务。
6. 首次任务开启人工审核，发布范围选择“仅本站”。
7. 审核草稿并在前台检查排版和引用事实。
8. 本站流程稳定后，再启用 WordPress 或通用 API 分发。

## 领导演示建议

建议按以下顺序演示，不从 Docker 或数据库开始：

```text
企业资料 -> 知识库 -> 模型配置 -> 创建任务 -> 草稿与风险审核
        -> 前台文章 -> 数据分析 -> 多平台分发
```

详细演示和交付检查项见 [领导交付说明](docs/LEADERSHIP_HANDOFF.md)。

## 安全与运维

- 生产环境关闭 `APP_DEBUG`
- 使用独立数据库密码、管理员密码和模型 API Key
- 上线前备份数据库与上传目录
- 分发渠道密钥应通过后台加密保存
- 系统更新执行和回滚默认关闭，启用前必须完成备份与权限检查
- 生产升级涉及安全迁移时，必须按迁移提示停机并排空旧进程

## 版本

当前点签GEO交付版本为 `v1.0.0`。默认关闭远程自动更新检查；发布自有版本和 Tag 后，可再启用更新中心。

## 开源基础与许可

点签GEO基于 GEOFlow 进行二次开发，保留原项目的 Apache License 2.0 授权、版权声明和 NOTICE。点签GEO在此基础上完成了品牌重构、本地部署适配、模型配置、交付文档和面向业务演示的界面优化。

分发或交付本项目时，请同时保留 [LICENSE](LICENSE)、[NOTICE](NOTICE) 和 [第三方声明](THIRD_PARTY_NOTICES.md)。
