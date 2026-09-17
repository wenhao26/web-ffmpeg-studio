<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\BinaryNotFoundException;

/**
 * ============================================================================
 * BinaryChecker — FFmpeg/FFprobe 二进制定位与可执行性校验服务
 * ============================================================================
 *
 * 职责:
 *   1) 从 config/ffmpeg.php 的 [binaries] 段取得二进制路径
 *      （含环境变量覆盖与平台推导逻辑，见该配置文件头注释）；
 *   2) 每次执行外部进程前校验文件存在且具备可执行权限；
 *   3) 校验通过的结果在进程生命周期内缓存，避免 FFprobe 高频探测时
 *      反复触碰文件系统。
 *
 * 安全契约:
 *   - file_exists(): 防路径不存在引发的运行时错误
 *   - is_executable(): 防“存在但无执行权限”的静默失败
 *   - 正斜杠规约: 统一反斜杠为 / 使校验与 Windows/Linux 路径均可工作
 *
 * 典型调用链 (Phase 2 controllers):
 *   Controller -> BinaryChecker::checkFfmpeg() -> CommandBuilder(注入)
 *              -> BinaryChecker::checkFfprobe() -> FFprobe 探测会话
 *
 * 生命周期: 单例式的服务对象，缓存仅在当前进程内有效；
 *           配置变更后需显式调用 clearCache() 使新路径生效。
 * ============================================================================
 */
class BinaryChecker
{
    /**
     * raw 配置段（['ffmpeg' => ..., 'ffprobe' => ...]），
     * 保存为原始可选路径，不做解析缓存以保持配置变更可感知。
     *
     * @var array<string, string>
     */
    private array $binaries;

    /**
     * 校验通过后的 ffmpeg 绝对路径缓存；null 表示尚未校验/已失效。
     */
    private ?string $cachedFfmpeg = null;

    /**
     * 校验通过后的 ffprobe 绝对路径缓存；null 表示尚未校验/已失效。
     */
    private ?string $cachedFfprobe = null;

    /**
     * @param array $config config/ffmpeg.php 的全部配置数组
     */
    public function __construct(array $config)
    {
        // 只摘取 binaries 段；复用同一份数组到配置热更新场景。
        $this->binaries = $config['binaries'];
    }

    /**
     * 校验并返回 FFmpeg 可执行文件路径。
     *
     * 首次调用触发实际文件系统校验；幂等，后续调用直接命中缓存。
     *
     * @return string 可执行文件绝对路径
     *
     * @throws BinaryNotFoundException 文件不存在或不可执行时抛出
     */
    public function checkFfmpeg(): string
    {
        // 双检锁语义：cachedFfmpeg 为 null 才做文件系统 I/O；
        // 通过后填充缓存，避免同批次多次转码反复 stat。
        if ($this->cachedFfmpeg === null) {
            $path = (string) $this->binaries['ffmpeg'];
            $this->assertExecutable($path, 'FFmpeg');
            $this->cachedFfmpeg = $path;
        }

        return $this->cachedFfmpeg;
    }

    /**
     * 校验并返回 FFprobe 可执行文件路径。
     *
     * @return string 可执行文件绝对路径
     *
     * @throws BinaryNotFoundException 文件不存在或不可执行时抛出
     */
    public function checkFfprobe(): string
    {
        if ($this->cachedFfprobe === null) {
            $path = (string) $this->binaries['ffprobe'];
            $this->assertExecutable($path, 'FFprobe');
            $this->cachedFfprobe = $path;
        }

        return $this->cachedFfprobe;
    }

    /**
     * 清空已缓存的有效路径，用于配置热更新 / 二进制的场景切换
     * （例如开发机切换系统全局 ffmpeg 与仓库内置 ffmpeg）。
     */
    public function clearCache(): void
    {
        $this->cachedFfmpeg  = null;
        $this->cachedFfprobe = null;
    }

    /**
     * 核心校验实现：存在性 + 可执行权限。
     *
     * @param string $path 待校验的二进制绝对路径
     * @param string $name 用于错误信息的人类可读名称（FFmpeg / FFprobe）
     *
     * @throws BinaryNotFoundException 任一条件失败即抛出并携带完整路径便于排障
     */
    private function assertExecutable(string $path, string $name): void
    {
        // 路径统一：Windows 使用反斜杠、Linux 使用正斜杠，
        // 统一替换为 / 可让 file_exists/is_executable 在 PHP 内
        // 跨平台保持一致判断行为。
        $normalized = str_replace('\\', '/', $path);

        // 1) 文件存在性：缺失时立即失败，避免 ProcessRunner 打出令人困惑的
        //    “不是内部或外部命令”类 shell 错误。
        if (!file_exists($normalized)) {
            throw new BinaryNotFoundException("{$name} 二进制文件不存在: {$normalized}");
        }

        // 2) 可执行权限：Linux 下缺失 +x 位、Windows 下非可执行文件均在该检查覆盖。
        //    提示运维 check 相应文件系统挂载 / 权限位，而非在运行时静默抛错。
        if (!is_executable($normalized)) {
            throw new BinaryNotFoundException("{$name} 二进制文件缺失可执行权限: {$normalized}");
        }
    }
}