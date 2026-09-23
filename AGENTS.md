# AGENTS.md — AI 协作总入口（唯一事实源）

> 任何 AI 编码代理（opencode / Cursor / Claude Code / Copilot 等）在本仓库开工前，**必须**先完整读本文件。
> 其他入口文件（`CLAUDE.md`、`.github/copilot-instructions.md`）仅为指向本文件的薄壳。
> 本文件与代码冲突时，**以代码为准**，并回头修正本文件。

## 1. 项目是什么

Web FFmpeg Studio：Vue 3 + Webman(Workerman) + FFmpeg 的**在线音视频转码工作台**（浏览器配置参数 → 后端异步转码 → SSE 实时进度 → 产物下载/播放）。

- 前端：Vite dev `5173`（代理 `/api`、`/storage` → `127.0.0.1:8787`）
- 后端：Webman `0.0.0.0:8787`，进程池 = 2×web + 2×task-worker + 1×monitor

## 2. 必读文档（按此顺序）

1. `README.md` — 快速启动（FFmpeg 二进制放 `bin/ffmpeg/{windows,linux}/`，或百度网盘下载）
2. `docs/项目需求.md` — 需求与验收标准（文档与代码冲突时以代码为准修文档）
3. `PROJECT.md` — 架构与目录结构
4. `STORAGE_POLICY.md` — 存储生命周期（输入 24h / 输出 72h / 临时 1h，`CleanupService` 定时清理）
5. `WORKFLOW.md` — 迭代工作流与 AI 循环
6. `openapi.yaml` — API 机器可读契约（**改任何接口必须同步改它**）
7. `docs/decisions/` — 架构决策记录（ADR）；动相关设计前先看是否为"故意设计"
8. `.cursor/rules/ffmpeg-backend.md` / `ffmpeg-frontend.md` — 领域编码与安全规则
9. `CHANGELOG.md` — 版本历史；`CONTRIBUTING.md` — 提交流程

## 3. 架构速览（改代码前必须知道）

- **任务状态存储**：`backend/storage/tasks/*.json`（文件型，跨 2 个 task-worker 进程读写，`.claim.lock` flock 队列）。**没有** Redis/数据库——这是 ADR-001 的故意设计，勿"优化"成缓存或入库。
- **双执行路径**：短命令（ffprobe）走 `ProcessRunner`（Symfony `Process::fromShellCommandline`）；长转码走 `TaskRunner::run()`（原生 `proc_open`，在 task-worker 进程）——见 ADR-002。
- **安全三段链**：`InputSanitizer`（值域/白名单校验）→ `CommandBuilder`（`escapeshellarg()` 序列化）→ `ProcessRunner`/`TaskRunner`（超时沙箱）。三段职责**不得合并或绕过**。
- **HLS 特例**：`-hls_segment_filename "...segment_%04d.ts"` 刻意手工引号、**不**进 `escapeshellarg()`——Windows 下 `%` 会被替换为空格（ADR-003）。勿"顺手修复"。
- **异步模型**：`POST /api/ffmpeg/execute` 只登记 `status=pending` 即返回 `task_id`；进度经 `GET .../stream`（SSE，0.3s 轮询）推送，`GET .../status` 为断线回退（ADR-004）。
- **前端栈**：Vue 3 `<script setup lang="ts">` + Pinia + Tailwind + **Naive UI**（不是 Element Plus）。类型集中在 `frontend/src/types/index.ts`（注意其 `ExecuteResponse` 已过时，实际以 `openapi.yaml` 为准）。

## 4. 红线（绝对禁止）

1. 禁止绕过/弱化安全三段链：任何新动态参数必须过 `InputSanitizer` 白名单 + `escapeshellarg()`。
2. 禁止 `git push --force`、删除 `.git`、改写历史；**未获用户明示，禁止 commit/push**。
3. 禁止把 `backend/vendor`、`frontend/node_modules`、`bin/ffmpeg`、`backend/storage`、`.env` 纳入版本控制或修改依赖锁定。
4. 禁止把任务状态改回内存缓存 / 引入数据库 / 改队列语义（除非先更新 ADR-001 并经人工确认）。
5. 禁止直接 `exec()`/`shell_exec()` 拼接用户输入；禁止关闭/放宽进程超时。
6. 禁止引入已否决技术栈：Element Plus、SCSS（样式一律 Tailwind）。
7. **验证未通过前，禁止声称"完成"**（见 §5）。

## 5. 验证命令（改完必须跑，全绿才算完成）

```bash
# 后端：语法检查 + 单测（无需 composer install、无需 FFmpeg 二进制、CI 等价）
php backend/tests/unit/run.php
find backend -name "*.php" -not -path "*/vendor/*" -exec php -l {} \;   # Windows PowerShell 见 §5.1

# 前端：类型检查 + 构建（必须零错误零警告）
cd frontend && npm run build

# 端到端冒烟（可选，需本机 FFmpeg + 后端已启动）
cd backend && php -d error_reporting="E_ALL & ~E_DEPRECATED" tests/smoke_test.php
```

### 5.1 Windows PowerShell 等价语法检查

```powershell
Get-ChildItem backend -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch 'vendor' } | ForEach-Object { php -l $_.FullName }
```

## 6. 契约同步规则（防文档漂移）

| 你改了… | 必须同步… |
|---|---|
| `backend/config/route.php` 或任一控制器响应结构 | `openapi.yaml` + `docs/项目需求.md` §2.2 |
| `config/ffmpeg.php` 白名单/超时/存储策略 | `docs/项目需求.md` 对应节 + `STORAGE_POLICY.md`（如涉及存储） |
| 新增架构级决策 | `docs/decisions/ADR-00N-*.md`（新建） |
| 任何文档与代码冲突 | **改文档**（代码是事实） |
| 对外可见的版本变化 | `CHANGELOG.md` |

## 7. AI 代码审查 Checklist（每个改动集逐项自检后才可交付）

- [ ] `php backend/tests/unit/run.php` 全部 PASS
- [ ] `npm run build` 零错误
- [ ] 未改业务源码红线文件（本任务为文档/测试/CI 时：`git status` 确认 `backend/app`、`backend/config`、`frontend/src` 无变更）
- [ ] 新增动态参数已过 `InputSanitizer` 白名单 + `CommandBuilder` 转义
- [ ] PHP 保留 `declare(strict_types=1);`，PSR-12，命名规范（PascalCase 类 / camelCase 方法）
- [ ] 注释只写 Why 不写 What；**无**机械化步骤注释（`// 1. xxx`）与复述型注释
- [ ] 未触碰 `vendor` / `node_modules` / `bin` / `storage` / `.env`
- [ ] 接口变更已同步 `openapi.yaml`；架构决策已落 ADR
- [ ] 与既有 ADR 无冲突（有冲突则先修订 ADR 再动代码）

## 8. 风格速查

- **PHP**：PSR-12、4 空格、`declare(strict_types=1)`、Service 层抛 `App\Exception\*`（由全局 `Handler` 统一转契约 JSON），控制器只做"参数提取 + 服务编排 + `ApiResponse` 包装"。
- **前端**：`<script setup lang="ts">`、Composition API、Pinia、Tailwind、Naive UI；TS 严格（`noUnusedLocals/Parameters` 开启）；`npm run build` = `vue-tsc -b` + `vite build`。
- **测试**：单测 = `backend/tests/unit/` 独立脚本（不依赖 composer/phpunit/二进制），断言失败抛异常、`run.php` 汇总退出码。
- **契约**：所有 JSON 响应 = `{code, message, data}`；HTTP 状态与业务 `code` 同值。

## 9. 推荐工作流

见 `WORKFLOW.md`「AI 迭代循环」：读本文件 → 列 todo（含验收标准）→ 小步实现 → 跑 §5 验证 → 过 §7 checklist → 请人工确认 → 下一步。**人未确认不进下一步，人未明示不 commit。**
