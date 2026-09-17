<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\ProcessTimeoutException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * ============================================================================
 * ProcessRunner — 进程沙箱执行器
 * ============================================================================
 *
 * 职责:
 *   以 Symfony Process 为底座安全执行外部命令（FFmpeg / FFprobe），
 *   提供三道防线：
 *
 *   1) Token 安全：由上游 CommandBuilder 已完成转义，本类不再接触拼接；
 *   2) 硬性超时：setTimeout 整体超限即 kill 整个进程树，防止死循环与
 *      巨大媒体拖垮 worker（超时来源 config/ffmpeg.php -> process.timeout）；
 *   3) 输出管控：stdout/stderr 全量捕获，FFmpeg 进度（stderr 上以
 *      \r 刷新）通过回调逐行上抛，供 SSE 进度广播订阅。
 *
 * 进度约定:
 *   FFmpeg 的进度统计是写到 stderr 且用 \r 回退的行（如
 *   `frame= 120 fps= ...`），每行结尾无 \n；Symfony 缓冲区按块回调，
 *   本类负责按 \r|\n 先切片再回调，避免半行乱码。
 *
 * 版本要求:
 *   stdout 采用 Process::fromShellCommandline() 方式执行，兼容
 *   Windows cmd 与 Linux sh 的引号规则差异（Symfony 内部处理）。
 * ============================================================================
 */
class ProcessRunner
{
    /**
     * 硬性超时（秒），来自 config/ffmpeg.php -> process.timeout。
     */
    private int $timeout;

    public function __construct(int $timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * 执行一条外部进程命令并捕获输出。
     *
     * @param string          $command           完整命令行（已由 CommandBuilder 转义）
     * @param callable|null  $progressCallback  收到 stderr 行时的回调 (string $line): void
     *
     * @return array{
     *   exit_code:int,   进程退出码；异常终止为 -1
     *   stdout:string,   标准输出全文
     *   stderr:string,   标准错误全文（含 FFmpeg 日志）
     *   duration:float,  执行耗时（秒，microtime 精度）
     * }
     *
     * @throws ProcessTimeoutException 进程超过硬性超时被强制终止
     */
    public function execute(string $command, ?callable $progressCallback = null): array
    {
        // 1) 用 ShellCommandline 构造：保留引号语义，Symfony 在 Windows
        //    下会包上 cmd 外壳并在超时 kill 时清理子进程树。
        $process = Process::fromShellCommandline($command);

        // 2) 双超时：整体超时 + 空闲超时（FFmpeg 长时间无输出也算卡死）。
        //    (float) 强转满足 Symfony 8.x 方法签名。
        $process->setTimeout((float) $this->timeout);
        $process->setIdleTimeout((float) $this->timeout);

        $start = microtime(true);

        try {
            // 3) 阻塞式 run() + 逐块回调：tpye 区分 OUT/ERR，
            //    进度只在 ERR 通道出现，其余直接丢弃以降低 SSE 噪音。
            $process->run(function ($type, string $buffer) use ($progressCallback): void {
                // 只关注 stderr（FFmpeg 把日志/进度全写在 stderr）。
                if ($type !== Process::ERR) {
                    return;
                }

                // 4) 进度行按 \r\n\r\n / \n 切分：FFmpeg 进度是 \r 刷新
                //    的同行追加，经典“time=" 行至此仍是一条完整可读记录。
                foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                    if ($line === '') {
                        continue; // 空行无消费价值，避免回调风暴
                    }
                    if ($progressCallback !== null) {
                        ($progressCallback)($line);
                    }
                }
            });
        } catch (ProcessTimedOutException $e) {
            // Symfony 在超时后已 kill 进程树；这里转为域内异常，
            // 携带上限时长便于控制器翻译成 5xx + 进度中断语义。
            throw new ProcessTimeoutException(
                '进程执行超时，已强制终止 (上限 ' . $this->timeout . ' 秒)',
                0,
                $e
            );
        }

        // 5) 任务实例侧统计：退出码（null 归并为 -1）、全文输出与耗时。
        return [
            'exit_code' => $process->getExitCode() ?? -1,
            'stdout'    => $process->getOutput(),
            'stderr'    => $process->getErrorOutput(),
            'duration'  => microtime(true) - $start,
        ];
    }
}