<?php
declare(strict_types=1);

namespace app\process;

use App\Service\TaskCache;
use App\Service\TaskRunner;
use Workerman\Timer;
use Workerman\Worker;

/**
 * ============================================================================
 * TaskWorker — 独立转码任务消费进程
 * ============================================================================
 *
 * 与 Web worker 分离，专门领取并执行 FFmpeg 任务：
 *   - Web 侧 execute 只登记任务（pending）后立即返回；
 *   - 本进程定时轮询待执行任务，同步阻塞地跑 FFmpeg 并实时更新 TaskCache；
 *   - 因此长耗时转码不会阻塞处理 HTTP / SSE 的 web worker。
 *
 * 在 config/process.php 注册，可通过 count 调整并发转码数。
 * ============================================================================
 */
class TaskWorker
{
    /**
     * 进程启动：注册轮询定时器。
     */
    public function onWorkerStart(Worker $worker): void
    {
        // 每隔 0.5s 领取一个待执行任务；无任务时空转开销极小。
        Timer::add(0.5, function (): void {
            $task = TaskCache::claimNextPending();
            if ($task === null) {
                return;
            }

            try {
                (new TaskRunner())->run($task);
            } catch (\Throwable $e) {
                TaskCache::appendLog((string) $task['task_id'], '执行异常：' . $e->getMessage());
                TaskCache::fail((string) $task['task_id']);
            }
        });
    }
}
