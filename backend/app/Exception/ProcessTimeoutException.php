<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * FFmpeg/FFprobe 进程执行超时异常。
 *
 * 抛出处: ProcessRunner::execute() 捕获到 Symfony ProcessTimedOutException 时转抛。
 * 处理策略: Phase 2 全局异常处理器中映射为 504，SSE 频道收到终止信号
 * 后广播 task 失败事件，前端进度条恢复为可重试状态。
 *
 * @see \App\Service\ProcessRunner
 */
class ProcessTimeoutException extends \RuntimeException
{
}