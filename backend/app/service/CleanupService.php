<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 存储生命周期清理服务。
 *
 * 清理策略（docs/项目需求.md 2.5.2）：
 *   - inputs/  : 24 小时
 *   - outputs/ : 72 小时
 *   - temp/    : 1 小时
 *
 * 由 Webman 定时器在 bootstrap 中定期调用。
 */
class CleanupService
{
    /** 输入文件保留时长（秒） */
    private const TTL_INPUTS = 86400;

    /** 输出文件保留时长（秒） */
    private const TTL_OUTPUTS = 259200;

    /** 临时文件保留时长（秒） */
    private const TTL_TEMP = 3600;

    /**
     * 执行清理：过期文件 + 过期任务缓存。
     *
     * @return array{inputs_removed: int, outputs_removed: int, temp_removed: int, tasks_gc: int}
     */
    public static function run(): array
    {
        $projectDir = dirname(__DIR__, 2); // backend 的父级 = 项目根
        $storageDir = $projectDir . '/storage';
        $now = time();

        $inputsRemoved  = self::cleanDir($storageDir . '/inputs', $now - self::TTL_INPUTS);
        $outputsRemoved = self::cleanDir($storageDir . '/outputs', $now - self::TTL_OUTPUTS);
        $tempRemoved    = self::cleanDir($storageDir . '/temp', $now - self::TTL_TEMP);

        // 清理过期的任务缓存
        $tasksGc = TaskCache::gc();

        return compact('inputsRemoved', 'outputsRemoved', 'tempRemoved', 'tasksGc');
    }

    /**
     * 删除指定目录中修改时间早于 $threshold 的文件/空目录。
     */
    private static function cleanDir(string $dir, int $threshold): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $files = glob($dir . '/*', GLOB_MARK);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $path) {
            if (is_dir($path)) {
                // 空目录则删除
                if (count(glob($path . '*')) === 0) {
                    @rmdir($path);
                    $removed++;
                }
            } elseif (is_file($path) && filemtime($path) < $threshold) {
                @unlink($path);
                $removed++;
            }
        }

        return $removed;
    }
}
