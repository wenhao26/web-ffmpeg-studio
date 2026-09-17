<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 任务状态存储（基于文件，跨进程共享）。
 *
 * webman 是多进程模型（web worker 与 task-worker 是不同进程），
 * 静态变量无法跨进程共享，因此任务状态一律以 JSON 文件持久化，
 * 所有进程读写同一份文件。
 *
 * 注意：刻意不做内存缓存——任务由 task-worker 进程更新、由 web worker
 * 进程读取，若 web worker 缓存了旧值，SSE / status 将永远读到过期数据。
 * 任务文件体积很小，直接读盘即可保证跨进程一致性。
 */
class TaskCache
{
    /** 任务文件目录 */
    private static string $dir = '';

    /**
     * 初始化任务目录。
     */
    private static function ensureDir(): void
    {
        if (self::$dir === '') {
            $projectDir = dirname(__DIR__, 2);
            self::$dir = $projectDir . '/storage/tasks';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0777, true);
            }
        }
    }

    /**
     * 获取任务文件路径。
     */
    private static function file(string $taskId): string
    {
        self::ensureDir();
        return self::$dir . '/' . $taskId . '.json';
    }

    /**
     * 登记一个新任务（状态 pending，等待 task-worker 消费）。
     *
     * @param array $exec 执行上下文：command / output_dir / output_format /
     *                    probe_duration / timeout
     */
    public static function create(string $taskId, string $fileId, array $exec): void
    {
        $now = time();
        $task = [
            'task_id'        => $taskId,
            'file_id'        => $fileId,
            'command'        => (string) ($exec['command'] ?? ''),
            'output_dir'     => (string) ($exec['output_dir'] ?? ''),
            'output_format'  => (string) ($exec['output_format'] ?? 'mp4'),
            'probe_duration' => (float) ($exec['probe_duration'] ?? 0.0),
            'timeout'        => (int) ($exec['timeout'] ?? 300),
            'status'         => 'pending',
            'progress'       => 0.0,
            'speed'          => '',
            'eta'            => '',
            'logs'           => [],
            'output_url'     => null,
            'output_size'    => null,
            'created_at'     => $now,
            'expires_at'     => $now + 21600,
        ];
        self::save($taskId, $task);
    }

    /**
     * 原子领取一个待执行任务：将最早的 pending 任务置为 processing 并返回。
     *
     * 使用独占文件锁避免多 task-worker 进程重复领取同一任务。
     *
     * @return array|null 领取到的任务，无任务时返回 null
     */
    public static function claimNextPending(): ?array
    {
        self::ensureDir();
        $lock = fopen(self::$dir . '/.claim.lock', 'c');
        if ($lock === false) {
            return null;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return null;
        }

        $claimed = null;
        $files = glob(self::$dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'pending') {
                continue;
            }
            $data['status'] = 'processing';
            $data['started_at'] = time();
            @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
            $claimed = $data;
            break;
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $claimed;
    }

    public static function get(string $taskId): ?array
    {
        $file = self::file($taskId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function updateStatus(string $taskId, string $status): void
    {
        self::mutate($taskId, static function (array &$task) use ($status): void {
            $task['status'] = $status;
        });
    }

    public static function updateProgress(string $taskId, float $progress, string $speed = '', string $eta = ''): void
    {
        self::mutate($taskId, static function (array &$task) use ($progress, $speed, $eta): void {
            $task['progress'] = $progress;
            if ($speed !== '') $task['speed'] = $speed;
            if ($eta !== '') $task['eta'] = $eta;
        });
    }

    public static function appendLog(string $taskId, string $line): void
    {
        self::mutate($taskId, static function (array &$task) use ($line): void {
            $logs = &$task['logs'];
            $logs[] = $line;
            if (count($logs) > 200) $logs = array_slice($logs, -200);
        });
    }

    public static function complete(string $taskId, string $outputUrl, ?int $outputSize = null): void
    {
        self::mutate($taskId, static function (array &$task) use ($outputUrl, $outputSize): void {
            $task['status'] = 'completed';
            $task['progress'] = 100.0;
            $task['output_url'] = $outputUrl;
            if ($outputSize !== null) $task['output_size'] = $outputSize;
        });
    }

    public static function fail(string $taskId, string $error = '', ?int $exitCode = null): void
    {
        self::mutate($taskId, static function (array &$task) use ($error, $exitCode): void {
            $task['status'] = 'failed';
            if ($error !== '') {
                $task['error'] = $error;
            }
            if ($exitCode !== null) {
                $task['exit_code'] = $exitCode;
            }
        });
    }

    /**
     * 清理过期任务（终态超期 + 未终态超期）。
     */
    public static function gc(): int
    {
        self::ensureDir();
        $now = time();
        $removed = 0;
        foreach (glob(self::$dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if ($data === null || $now > ($data['expires_at'] ?? 0)) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 读-改-写：所有变更集中在此，避免并发下互相覆盖。
     */
    private static function mutate(string $taskId, callable $fn): void
    {
        $task = self::get($taskId);
        if ($task === null) {
            return;
        }
        $fn($task);
        self::save($taskId, $task);
    }

    /**
     * 写入任务到文件。
     */
    private static function save(string $taskId, array $task): void
    {
        self::ensureDir();
        @file_put_contents(self::file($taskId), json_encode($task, JSON_UNESCAPED_UNICODE));
    }
}
