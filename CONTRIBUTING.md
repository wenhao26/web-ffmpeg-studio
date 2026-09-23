# 为 Web FFmpeg Studio 做贡献

感谢关注本项目！本仓库以 **文档 + AI 协作可复现** 为第一原则，
所有贡献者（人类与 AI 代理）都适用同一套规则。

## 1. 开始之前

1. 完整阅读根目录 [`AGENTS.md`](AGENTS.md)（AI 代理的唯一指令入口，
   人类贡献者同样建议通读）。
2. 按 [`README.md`](README.md) 启动本地环境（FFmpeg 二进制、后端 8787、前端 5173）。
3. 浏览相关 [`docs/decisions/`](docs/decisions/)：你要改的行为可能是
   故意设计（ADR）；有冲突先修订 ADR 再动代码。

## 2. 分支与提交

- 基于 `main` 开发，**trunk-based**：小步提交、尽快合并。
- 提交信息使用 [Conventional Commits](https://www.conventionalcommits.org/)：
  `feat:` / `fix:` / `docs:` / `test:` / `chore:` / `refactor:`，可带中文描述。
- **禁止** `git push --force`、改写历史、删除 `.git`。
- 一次提交只做一件事；提交前自检 `git status` / `git diff`，
  确认没有带入 `vendor`、`node_modules`、`bin/`、`storage/`、`.env`。

## 3. 验证（全绿才能提 PR）

```bash
# 后端：语法 + 单测（无需 composer install、无需 FFmpeg 二进制）
php backend/tests/unit/run.php

# 前端：类型检查 + 构建
cd frontend && npm run build

# 可选：端到端冒烟（需本机 FFmpeg + 后端已启动）
cd backend && php -d error_reporting="E_ALL & ~E_DEPRECATED" tests/smoke_test.php
```

Windows PowerShell 的全量 `php -l` 写法见 `AGENTS.md` §5.1。
GitHub Actions（`.github/workflows/ci.yml`）会在 push/PR 时执行等价检查。

## 4. 改动的同步义务（防文档漂移）

| 你改了… | 必须同步… |
|---|---|
| 路由 / 控制器响应结构 | `openapi.yaml` + `docs/项目需求.md` §2.2 |
| `config/ffmpeg.php` 白名单 / 超时 / 存储 | `docs/项目需求.md` + `STORAGE_POLICY.md` |
| 架构级决策 | 新增/修订 `docs/decisions/ADR-*.md` |
| 对外可见变化 | `CHANGELOG.md` |

## 5. 代码规范要点

- PHP：PSR-12、4 空格、`declare(strict_types=1);`；赋值单空格不做等号对齐（`$a = 1;`，禁止补空格对齐）；注释写 Why 不写 What；
  禁止机械化步骤注释（`// 1. xxx`）。
- 前端：Vue 3 `<script setup lang="ts">`、Pinia、Tailwind、**Naive UI**
  （禁止 Element Plus / SCSS）。
- 安全：任何新动态参数必须过 `InputSanitizer` 白名单 +
  `CommandBuilder` 的 `escapeshellarg()`；禁止裸 `exec()` 拼接。

## 6. Pull Request

- PR 描述写清：动机、改动点、验证命令输出（或截图）。
- 过一遍 `AGENTS.md` §7 审查 Checklist。
- CI 全绿 + 至少一次人工 review 后合并。

## 7. 安全问题

发现命令注入、路径穿越、越权访问等安全问题，请**不要**公开开 issue，
改为在 GitHub 仓库 Security 页私下报告（或联系维护者），给修复留出窗口期。
