# Project Architecture & Technical Map (PROJECT.md): Web FFmpeg Studio

> 快速导航：新人/AI 先读 [`AGENTS.md`](AGENTS.md) → 本文件 → [`docs/项目需求.md`](docs/项目需求.md)。
> 本文件与代码冲突时以代码为准并回改本文件。

## 1. 技术栈选型 (Tech Stack)

### 后端 (Backend)
- **Language**: PHP ≥ 8.1（全量 `declare(strict_types=1);`、PSR-12）
- **Framework**: Webman (Workerman) — 进程池 `2×web + 2×task-worker + 1×monitor`
- **Process**: symfony/process（短命令）+ 原生 `proc_open`（长转码，见 ADR-002）
- **Task state**: 文件型任务队列 `backend/storage/tasks/*.json`（无 Redis/数据库，见 ADR-001）
- **Engine**: 项目内置静态编译 FFmpeg / FFprobe（`bin/ffmpeg/{windows,linux}/`，不入库）

### 前端 (Frontend)
- **Framework**: Vue 3 (Composition API, `<script setup lang="ts">`) + Vite + TypeScript（严格模式）
- **State Management**: Pinia（`media` / `params` / `task` / `ui`）
- **UI Component Library**: **Naive UI**（已确认；Element Plus 已否决）
- **Styling**: **Tailwind CSS**（已确认；SCSS 已否决）

### 实时通信
- **Progress**: SSE（`GET /api/ffmpeg/task/{id}/stream`）+ REST 回退（`/status`），见 ADR-004

---

## 2. 企业级项目目录结构 (Directory Tree)

```text
web-ffmpeg-studio/
├── AGENTS.md                 # AI 协作总入口（唯一事实源；CLAUDE.md 等为其薄壳）
├── CLAUDE.md / .github/copilot-instructions.md   # 指向 AGENTS.md 的薄壳
├── README.md                 # 快速启动（含 FFmpeg 二进制网盘下载）
├── PRD.md                    # 产品需求与目标
├── PROJECT.md                # 架构与技术蓝图（本文件）
├── STORAGE_POLICY.md         # 存储分区与生命周期（TTL 清理）
├── WORKFLOW.md               # 迭代工作流（Phase 路线图 + AI 循环）
├── CONTRIBUTING.md           # 提交流程 / 验证命令 / 同步义务
├── CHANGELOG.md              # 版本历史（Keep a Changelog）
├── LICENSE                   # MIT
├── openapi.yaml              # API 机器可读契约（改接口必同步）
├── .nvmrc / .editorconfig / .env.example
├── .cursor/rules/            # 领域规则（*.md，glob 绑定 backend/**、frontend/**）
├── .github/
│   ├── copilot-instructions.md
│   └── workflows/ci.yml      # CI：php -l + 单测 + npm run build
├── docs/
│   ├── 0-开始之前.md          # Phase 0 启动提示词（历史存档）
│   ├── 项目需求.md            # 需求与验收标准（§2.2 = API 契约正文）
│   └── decisions/            # ADR-001~004（架构决策记录）
├── bin/                      # FFmpeg 二进制（windows/ + linux/，不入库）
├── storage/                  # 运行期产物（inputs/outputs/temp，不入库）
├── backend/                  # PHP / Webman 后端
│   ├── app/
│   │   ├── controller/       # Media / Ffmpeg / Task（+ Index/Debug 附带）
│   │   ├── service/          # BinaryChecker InputSanitizer CommandBuilder
│   │   │                     # ProcessRunner UploadService ProbeService
│   │   │                     # TaskRunner TaskCache CleanupService
│   │   ├── process/          # Http / TaskWorker / Monitor 进程
│   │   ├── Exception/        # 业务异常（全局 Handler 统一映射状态码）
│   │   └── Support/          # ApiResponse / Uuid
│   ├── config/
│   │   ├── ffmpeg.php        # 二进制 / 超时 / 存储 / 上传 / 清理 / 白名单
│   │   └── route.php         # 6 条契约路由
│   ├── support/              # 框架胶水：bootstrap(含每小时清理 Timer) / Handler
│   ├── tests/
│   │   ├── smoke_test.php    # 端到端冒烟（需 FFmpeg）
│   │   └── unit/             # 独立单测（无需 vendor/二进制，CI 入口 run.php）
│   ├── windows.php / start.php / composer.json / vendor/
├── frontend/                 # Vue 3 前端
│   └── src/
│       ├── App.vue → AppContent.vue → layouts/MainLayout.vue
│       ├── components/       # FileUpload MediaInfo ProgressPanel ResultCard
│       │   └── ParamForm/    # BasicTranscode GeometryCrop Watermark ExpertMode
│       ├── stores/           # Pinia：media / params / task / ui
│       ├── api/              # axios 实例与接口封装
│       ├── utils/commandBuilder.ts   # 前端命令预览（镜像后端逻辑）
│       └── types/index.ts    # 共享类型
└── (app/mall、app/command 等为 Webman 模板遗留，业务未使用)
```

---

## 3. 关键架构速览

1. **安全三段链**（不可绕过）：`InputSanitizer`（值合法）→ `CommandBuilder`（`escapeshellarg` 序列化）→ `ProcessRunner`/`TaskRunner`（超时沙箱）。
2. **异步模型**：`POST /execute` 校验 + 登记 `pending` 即返回 `task_id`；task-worker 领取执行；进度经 SSE `/stream` 推送，`/status` 回退（ADR-002/004）。
3. **任务队列**：JSON 文件 + `.claim.lock` flock 领取，无外部存储（ADR-001）。
4. **HLS 特例**：`segment_%04d.ts` 手工引号绕过 `escapeshellarg`（Windows `%` 陷阱，ADR-003）。
5. **存储清理**：Workerman Timer 每小时跑 `CleanupService`（inputs 24h / outputs 72h / temp 1h + 任务 GC），见 `STORAGE_POLICY.md`。
6. **错误模型**：Service 抛 `App\Exception\*` → 全局 `Handler` 映射 400/422/500/504 契约 JSON；响应信封恒为 `{code, message, data}`。

## 4. 契约与验证

- API 契约：[`openapi.yaml`](openapi.yaml)（改任何接口必须同步）。
- 验证命令（全绿才算完成）：见 `AGENTS.md` §5 —— `php backend/tests/unit/run.php` 与 `cd frontend && npm run build`。
- 架构决策：[`docs/decisions/`](decisions/)；编码规则：[`.cursor/rules/`](../.cursor/rules/)。
