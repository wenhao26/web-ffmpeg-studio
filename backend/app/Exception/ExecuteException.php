<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * FFmpeg 执行失败异常（进程级 5xx）。
 *
 * 抛出处: FfmpegController::execute（退出码非 0 / 产物缺失）。
 * 处理策略: 全局异常处理器映射为 500，data 携带 execute_context（命令、stderr）。
 */
class ExecuteException extends \RuntimeException
{
    /**
     * 执行上下文：command / stderr 尾部 / exit_code 等调试信息。
     *
     * @var array<string, mixed>
     */
    protected array $context;

    /**
     * @param array<string, mixed> $context 执行失败现场（命令与 stderr）
     */
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}