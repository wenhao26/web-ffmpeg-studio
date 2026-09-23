# GitHub Copilot Instructions

本仓库所有 AI 编码指令的唯一事实源是根目录 [`AGENTS.md`](../AGENTS.md)。

编码前先读 `AGENTS.md`，重点：

1. §3 架构速览 — 文件型任务队列、双执行路径、安全三段链、HLS `%04d` 特例
2. §4 红线 — 禁止绕过 `InputSanitizer` + `escapeshellarg`、禁止 force push、禁止 Element Plus/SCSS
3. §5 验证命令 — `php backend/tests/unit/run.php` 与 `cd frontend && npm run build` 必须全绿
4. §7 审查 Checklist — 交付前逐项自检

本文件仅为指路薄壳，不重复 `AGENTS.md` 内容。
