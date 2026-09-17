<?php
declare(strict_types=1);

namespace app\controller;

use App\Service\TaskCache;
use support\Request;
use support\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\ServerSentEvents;
use Workerman\Timer;

/**
 * ============================================================================
 * 任务状态控制器（SSE 进度流 + REST 状态查询）
 * ============================================================================
 *
 * 路由（config/route.php）:
 *   GET  /api/ffmpeg/task/{taskId}/stream  SSE 实时进度推送
 *   GET  /api/ffmpeg/task/{taskId}/status  REST 状态查询（SSE 回退）
 *
 * SSE 事件格式（docs/项目需求.md 2.2.4）:
 *   event: progress  data: {"type":"progress","task_id":"...","progress":67.5,"speed":"2.5x","eta":"00:00:30"}
 *   event: log      data: {"type":"log","task_id":"...","line":"frame=..."}
 *   event: complete data: {"type":"complete","task_id":"...","code":200,"data":{...}}
 *   event: error    data: {"type":"error","task_id":"...","code":500,"message":"..."}
 * ============================================================================
 */
class TaskController
{
    /**
     * SSE 实时进度流。
     *
     * @param Request  $request
     * @param string   $taskId
     *
     * @return Response
     */
    public function stream(Request $request, string $taskId): Response
    {
        $task = TaskCache::get($taskId);
        if ($task === null) {
            return json(['code' => 404, 'message' => '任务不存在', 'data' => null], 404);
        }

        $connection = $request->connection;

        // 已推送的日志条数 / 上次推送的进度，避免重复推送
        $sentLogs = 0;
        $sentProgress = -1.0;
        $timerId = 0;

        // 使用 Workerman Timer 异步轮询任务状态，不阻塞 worker 事件循环。
        // 首轮即推送全部历史日志（快照），此后仅推送增量。
        $timerId = Timer::add(0.3, function () use ($connection, $taskId, &$timerId, &$sentLogs, &$sentProgress) {
            // 客户端断开：清理定时器，防止泄漏
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                Timer::del($timerId);
                return;
            }

            $task = TaskCache::get($taskId);
            if ($task === null) {
                Timer::del($timerId);
                return;
            }

            // 1. 增量推送日志
            $logs = $task['logs'] ?? [];
            if (count($logs) > $sentLogs) {
                foreach (array_slice($logs, $sentLogs) as $line) {
                    $this->emit($connection, 'log', $this->encode(['type' => 'log', 'task_id' => $taskId, 'line' => $line]));
                }
                $sentLogs = count($logs);
            }

            // 2. 处理中：推送进度
            if ($task['status'] === 'processing') {
                $progress = round((float) ($task['progress'] ?? 0), 1);
                if ($progress !== $sentProgress) {
                    $sentProgress = $progress;
                    $this->emit($connection, 'progress', $this->encode($this->progressPayload($task)));
                }
                return;
            }

            // 3. 终态：推送 complete / error 并结束
            if ($task['status'] === 'completed') {
                $this->emit($connection, 'complete', $this->encode([
                    'type'    => 'complete',
                    'task_id' => $taskId,
                    'code'    => 200,
                    'data'    => [
                        'command'   => $task['command'],
                        'output'    => implode("\n", $logs),
                        'file_url'  => $task['output_url'],
                        'file_size' => $task['output_size'],
                    ],
                ]));
                $this->endStream($connection);
                Timer::del($timerId);
                return;
            }

            if ($task['status'] === 'failed') {
                $message = (string) ($task['error'] ?? '');
                $this->emit($connection, 'error', $this->encode([
                    'type'    => 'error',
                    'task_id' => $taskId,
                    'code'    => 500,
                    'message' => $message !== '' ? $message : 'FFmpeg 执行失败',
                    'data'    => [
                        'command'   => $task['command'],
                        'exit_code' => $task['exit_code'] ?? null,
                    ],
                ]));
                $this->endStream($connection);
                Timer::del($timerId);
            }
        });

        // 返回 SSE 响应头（不阻塞；后续数据由上面的 Timer 异步推送）。
        // 必须声明 Transfer-Encoding: chunked：workerman 对 text/event-stream
        // 只发送响应头、不带 Content-Length，直连客户端可读到连接关闭为止，
        // 但通过 Vite/nginx 等代理时，代理会将无长度响应视作 0 字节而提前结束。
        // 使用 chunked 后，后续每个事件以分块传输，代理可正确转发实时流。
        return response('', 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 以 chunked 分块推送一个 SSE 事件。
     *
     * Workerman 的 Http::encode 对「非 Response 对象」直接透传其字符串形式，
     * 因此发送 Chunk 对象即可在已开启 chunked 的连接上追加一个合法分块。
     */
    private function emit(TcpConnection $connection, string $event, string $data): void
    {
        $view = new ServerSentEvents(['event' => $event, 'data' => $data]);
        $connection->send(new Chunk((string) $view));
    }

    /**
     * 发送终止分块（0 长度），结束 chunked 响应体。
     */
    private function endStream(TcpConnection $connection): void
    {
        $connection->send(new Chunk(''));
    }


    /**
     * REST 状态查询（SSE 断线回退用）。
     */
    public function status(Request $request, string $taskId): Response
    {
        $task = TaskCache::get($taskId);
        if ($task === null) {
            return json(['code' => 404, 'message' => '任务不存在', 'data' => null], 404);
        }

        return json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'task_id'   => $task['task_id'],
                'status'    => $task['status'],
                'progress'  => $task['progress'],
                'speed'     => $task['speed'],
                'eta'       => $task['eta'],
                'logs'      => array_slice($task['logs'] ?? [], -100),
                'output_url'=> $task['output_url'],
                'error'     => $task['error'] ?? '',
                'exit_code' => $task['exit_code'] ?? null,
            ],
        ]);
    }

    /**
     * JSON 编码 SSE 数据负载（保留中文，不转义斜杠）。
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 构建进度事件 payload。
     */
    private function progressPayload(array $task): array
    {
        return [
            'type'      => 'progress',
            'task_id'   => $task['task_id'],
            'progress'  => round($task['progress'] ?? 0, 1),
            'speed'     => $task['speed'] ?? '',
            'eta'       => $task['eta'] ?? '',
        ];
    }
}
