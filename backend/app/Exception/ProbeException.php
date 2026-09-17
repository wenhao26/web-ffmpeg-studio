<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 媒体探测失败异常（业务层 4xx）。
 *
 * 抛出处: ProbeService（ffprobe 进程失败 / 输出 JSON 解析失败 / 无可用媒体流）。
 * 处理策略: 全局异常处理器映射为 422，data 携带 probe_context 便于前端诊断。
 */
class ProbeException extends \RuntimeException
{
    /**
     * 探测上下文（携带给前端/日志的原始信息，如 ffprobe stderr 摘要）。
     *
     * @var array<string, mixed>
     */
    protected array $context;

    /**
     * @param array<string, mixed> $context 探测失败时的诊断上下文
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