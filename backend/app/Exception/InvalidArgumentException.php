<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 入参校验失败异常（业务层 4xx）。
 *
 * 抛出处: InputSanitizer / CommandBuilder 的各类 assert* 方法。
 * 处理策略: Phase 2 全局异常处理器中映射为 400 + 具体错误信息，
 * 保证前端 Naive UI 可原样展示给用户修正表单。
 */
class InvalidArgumentException extends \InvalidArgumentException
{
}