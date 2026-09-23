# ADR-002: 双执行路径（web worker 短命令 + task-worker 长转码）

- 状态：已接受
- 日期：2026-09

## 背景

两类进程需求相反：

- ffprobe 探测、上传校验等**秒级**命令，放在请求内同步执行最简单；
- 视频转码**分钟级**，若在 web worker 内同步执行会阻塞后续所有
  HTTP / SSE 请求（尤其单实例部署时 web worker 数量有限）。

同时 Symfony Process 在本项目依赖版本中 `__construct()` 只接受数组命令，
字符串命令必须走 `fromShellCommandline()`。

## 决策

1. **短命令路径**：web worker 内经 `ProcessRunner`
   （Symfony `Process::fromShellCommandline` + 300s 硬超时）执行，用于
   ffprobe 探测等。
2. **长任务路径**：`POST /execute` 只做校验、构建命令、登记 `pending`
   即返回 `task_id`；独立 task-worker 进程 0.5s 轮询领取任务，经
   `TaskRunner::run()`（原生 `proc_open`）同步执行并把进度写回任务 JSON。
3. 进度消费：SSE `/stream` 与 REST `/status` 均只读任务 JSON，不直接
   触碰执行进程。

## 理由

- HTTP 响应即时性与长任务隔离兼得；
- 两条路径各自的超时/输出解析职责清晰（执行细节见各自类文档）。

## 后果

- 正面：web worker 永不被转码阻塞；SSE 可随时服务。
- 负面：存在两套执行实现，超时与 stderr 解析逻辑须分别维护；
  修改其中一条路径时必须检查另一条是否需要同步（见 `backend/tests/unit/`）。
- 禁止把长任务挪回 web worker 内同步执行。
