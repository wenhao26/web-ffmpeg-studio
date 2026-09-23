# ADR-004: SSE 进度推送 + REST 状态回退

- 状态：已接受
- 日期：2026-09

## 背景

转码进度需要实时呈现。可选：前端轮询 REST、WebSocket、SSE。

## 决策

1. **主通道 SSE**：`GET /api/ffmpeg/task/{taskId}/stream`，
   服务端 0.3s `Timer` 轮询任务 JSON：
   - 首轮推送全部历史日志快照，此后仅增量日志；
   - `processing` 期间仅在进度值变化时推送 `progress` 事件（去重）；
   - 终态推送 `complete` / `error` 后发送终止分块并关闭。
2. **回退通道 REST**：`GET .../status` 返回任务快照（日志取最近 100 行），
   供断线/不支持 SSE 的环境轮询。
3. **代理兼容**：SSE 响应显式带
   `Transfer-Encoding: chunked` 与 `X-Accel-Buffering: no`，
   避免 Vite/nginx 将无长度响应提前截断（见 `TaskController::stream` 注释）。
4. **不设应用层心跳**：服务端不发送 `:` 注释行；依赖连接层保活与代理配置。
5. **客户端断开**：服务端仅清理定时器，任务在后端继续执行，
   可凭 `task_id` 重连 `/stream` 或查 `/status`。

## 理由

- SSE 与现有 HTTP 栈无缝、实现成本低于 WebSocket；
- 任务状态本就落在文件 JSON 上，轮询读取与执行解耦（呼应 ADR-001/002）。

## 后果

- 正面：进度实时、断线可回退、执行与推送解耦。
- 负面：每条 SSE 连接占用一个定时器；高并发时需评估连接数。
- 前端契约：`EventSource` 为主、`/status` 轮询为辅，二者 payload 结构
  见根目录 `openapi.yaml`，改动须同步。
