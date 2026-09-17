<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 二进制缺失或不可执行异常。
 *
 * 抛出处: BinaryChecker::assertExecutable()。
 * 处理策略: Phase 2 全局异常处理器中映射为 500，并携带可读的
 * “二进制缺失，请检查 bin/ffmpeg 安装/挂载”提示（前后端约定）。
 *
 * @see \App\Service\BinaryChecker
 */
class BinaryNotFoundException extends \RuntimeException
{
}