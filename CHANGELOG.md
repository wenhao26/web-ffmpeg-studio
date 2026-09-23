# Changelog

本项目所有值得注意的变化都记录在此文件。
格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### Added

- 仓库协作基建：`AGENTS.md`（AI 唯一指令入口）+ `CLAUDE.md` /
  `.github/copilot-instructions.md` 薄壳
- 独立单元测试 `backend/tests/unit/`（InputSanitizer / CommandBuilder，
  无需 composer 与 FFmpeg 二进制）
- GitHub Actions CI（`.github/workflows/ci.yml`：php -l + 单测 + 前端构建）
- 机器可读 API 契约 `openapi.yaml`
- 架构决策记录 `docs/decisions/`（ADR-001 ~ ADR-004）
- `CONTRIBUTING.md`、`LICENSE`（MIT）、`.nvmrc`、`.editorconfig`、`.env.example`

### Fixed

- 文档漂移：`PROJECT.md`、`docs/0-开始之前.md`、`docs/项目需求.md`
  （execute 实际返回 `task_id/pending`、任务存储为文件型、组件树与
  实际代码对齐）、`WORKFLOW.md`、`STORAGE_POLICY.md`
- `.cursor/rules` 两条规则的 glob 失效与 Element Plus 残留

## [1.0.0] - 2026-09-23

首个正式版本。

### Added

- 文件上传（≤1 GiB，MIME 白名单）与 ffprobe 元数据探测
- 转码参数面板：容器/编码/CRF/码率/预设、时间剪切、裁剪缩放、
  文字与图片水印、HLS 切片、专家参数（白名单 token）
- 异步任务队列：`execute` 即时返回 `task_id`，task-worker 领取执行
- SSE 实时进度 + REST 状态回退、任务日志滚动展示
- 产物在线播放 / 下载（Range 支持，HLS 分片可播）
- 存储生命周期清理：输入 24h / 输出 72h / 临时 1h
- 安全三段链：`InputSanitizer` → `CommandBuilder` → 进程超时沙箱
- 后端冒烟测试 `backend/tests/smoke_test.php`

[Unreleased]: https://github.com/wenhao26/web-ffmpeg-studio/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/wenhao26/web-ffmpeg-studio/releases/tag/v1.0.0
