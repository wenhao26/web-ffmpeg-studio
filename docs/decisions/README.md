# 架构决策记录（ADR）

本目录记录 Web FFmpeg Studio 的关键架构决策。**动设计前先查这里**：
若相关行为是"故意设计"，须先修订对应 ADR 并经人工确认，才可改代码。

| 编号 | 决策 | 状态 |
|---|---|---|
| [ADR-001](ADR-001-file-based-task-store.md) | 任务状态用文件型存储（无 Redis/数据库） | 已接受 |
| [ADR-002](ADR-002-dual-execution-paths.md) | 双执行路径：web worker 短命令 + task-worker 长转码 | 已接受 |
| [ADR-003](ADR-003-hls-segment-manual-quoting.md) | HLS segment_%04d 手工引号、绕过 escapeshellarg | 已接受 |
| [ADR-004](ADR-004-sse-progress-rest-fallback.md) | SSE 进度推送 + REST 状态回退 | 已接受 |

命名规范：`ADR-NNN-kebab-case-title.md`，编号递增不复用。
