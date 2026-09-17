<?php
declare(strict_types=1);

namespace App\Service;

/**
 * ============================================================================
 * TaskRunner — 后台任务执行器
 * ============================================================================
 *
 * 由独立的 task-worker 进程调用，负责真正执行 FFmpeg 并实时更新 TaskCache。
 *
 * 为何独立于 Web 控制器：
 *   FFmpeg 执行是长耗时同步阻塞操作（proc_open + 轮询 stderr）。
 *   若放在 web worker 内执行，会独占该 worker，导致同一进程无法处理
 *   SSE / status / upload 等请求（Windows 下 webman 仅有一个 web worker）。
 *   因此执行逻辑下沉到专用进程，Web 侧只负责入队。
 * ============================================================================
 */
class TaskRunner
{
    /**
     * 输出容器格式 → 产物文件扩展名映射（只有白名单内格式可达此处）。
     */
    private const OUTPUT_EXTENSIONS = [
        'mp4'  => 'mp4',
        'webm' => 'webm',
        'mkv'  => 'mkv',
        'mov'  => 'mov',
    ];

    /**
     * 执行一个已领取（status=processing）的任务。
     *
     * @param array $task TaskCache::claimNextPending() 返回的任务记录
     */
    public function run(array $task): void
    {
        $taskId        = (string) $task['task_id'];
        $command       = (string) $task['command'];
        $outputDir     = (string) ($task['output_dir'] ?? '');
        $outputFormat  = (string) ($task['output_format'] ?? 'mp4');
        $timeout       = (int) ($task['timeout'] ?? 300);
        $probeDuration = (float) ($task['probe_duration'] ?? 0.0);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            TaskCache::appendLog($taskId, '无法启动 FFmpeg 进程');
            TaskCache::fail($taskId, '无法启动 FFmpeg 进程');
            return;
        }

        // stdout/stderr 非阻塞，stdin 关闭
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fclose($pipes[0]);

        $startTime = microtime(true);
        $exitCode  = null;
        $timedOut  = false;

        while (true) {
            $status = proc_get_status($process);

            // 每轮先把 stderr 缓冲区读空（fgets 在非阻塞下无数据返回 false）。
            // 必须成批读取：仅读一行会让 ffmpeg 短时间内输出的大量
            // 错误信息滞留在缓冲区，进程退出后即丢失，故障无从排查。
            $this->drainStderr($taskId, $pipes[2], $probeDuration);

            if (!$status['running']) {
                // 首个报告已退出的 status 才带真实 exitcode，先存下来。
                $exitCode = (int) $status['exitcode'];
                break;
            }

            // 硬性超时
            if ((microtime(true) - $startTime) > $timeout) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }

            // 等待 stderr 可读（最多 100ms），避免空转
            $read = [$pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 100000);
        }

        // 进程退出后缓冲区可能仍有最后一批输出（含真正的报错行），再读干净。
        $this->drainStderr($taskId, $pipes[2], $probeDuration);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === null || $exitCode === -1) {
            $exitCode = (int) $closeCode;
        }

        if ($timedOut) {
            $message = "任务超时（> {$timeout}s），已强制终止";
            TaskCache::appendLog($taskId, $message);
            TaskCache::fail($taskId, $message, $exitCode);
            return;
        }

        // 产物信息
        $ext = $outputFormat === 'hls' ? 'm3u8' : (self::OUTPUT_EXTENSIONS[$outputFormat] ?? 'mp4');
        $outputUrl = '/storage/outputs/' . $taskId . '/output.' . $ext;
        $filepath  = $outputDir . '/' . ($outputFormat === 'hls' ? 'output.m3u8' : 'output.' . $ext);
        $outputSize = is_file($filepath) ? (int) filesize($filepath) : null;

        if ((int) $exitCode !== 0) {
            TaskCache::fail($taskId, $this->extractError($taskId), $exitCode);
            return;
        }

        TaskCache::complete($taskId, $outputUrl, $outputSize);
    }

    /**
     * 把一条管道里当前所有可读行读空，逐行记录日志并解析进度。
     */
    private function drainStderr(string $taskId, $pipe, float $probeDuration): void
    {
        while (($line = fgets($pipe)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            TaskCache::appendLog($taskId, $line);
            $progress = $this->parseFfmpegProgress($line, $probeDuration);
            if ($progress !== null) {
                TaskCache::updateProgress($taskId, $progress['progress'], $progress['speed'], $progress['eta']);
            }
        }
    }

    /**
     * 从已记录的日志中提取失败原因（末尾若干条错误样式的行）。
     */
    private function extractError(string $taskId): string
    {
        $task = TaskCache::get($taskId);
        $logs = $task['logs'] ?? [];

        $errors = [];
        foreach (array_reverse($logs) as $line) {
            if (preg_match('/error|invalid|failed|unable|no such|not found|unsupported|denied/i', $line)) {
                $errors[] = $line;
                if (count($errors) >= 5) {
                    break;
                }
            }
        }

        if ($errors === [] && $logs !== []) {
            // 无关键字命中时，退回最后一行（通常是 ffmpeg 的概括性报错）。
            return (string) end($logs);
        }

        return implode("\n", array_reverse($errors));
    }

    /**
     * 解析 FFmpeg stderr 进度行（frame= fps= q= size= time= speed=）。
     */
    private function parseFfmpegProgress(string $line, float $probeDuration = 0.0): ?array
    {
        // 匹配 time=HH:MM:SS.mmm
        if (!preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $line, $timeMatches)) {
            return null;
        }
        $h = (int) $timeMatches[1];
        $m = (int) $timeMatches[2];
        $s = (float) $timeMatches[3];
        $elapsed = $h * 3600 + $m * 60 + $s;

        // 匹配 speed=x
        $speed = '';
        if (preg_match('/speed=([\d.]+x)/', $line, $speedMatches)) {
            $speed = $speedMatches[1];
        }

        // 估算进度
        $progress = 0.0;
        if ($elapsed > 0 && $probeDuration > 0) {
            $progress = min(($elapsed / $probeDuration) * 100, 100);
        }

        // 估算 ETA
        $eta = '';
        if ($speed !== '' && $speed !== '0x' && $elapsed > 0) {
            $speedNum = (float) str_replace('x', '', $speed);
            $remaining = $probeDuration - $elapsed;
            if ($remaining > 0 && $speedNum > 0) {
                $etaSecs = $remaining / $speedNum;
                $etaSecsInt = (int) $etaSecs;
                $eta = sprintf('%02d:%02d:%02d', (int) ($etaSecsInt / 3600), (int) (($etaSecsInt % 3600) / 60), $etaSecsInt % 60);
            }
        }

        return [
            'progress' => $progress,
            'speed'    => $speed,
            'eta'      => $eta,
            'duration' => $elapsed,
        ];
    }
}
