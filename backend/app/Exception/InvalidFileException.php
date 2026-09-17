<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 上传文件校验失败异常（业务层 4xx）。
 *
 * 抛出处: UploadService（大小超出 / MIME 不在白名单 / Magic Number 不匹配）。
 * 处理策略: 全局异常处理器映射为 400/413/415，携带可读原因供前端表单提示。
 */
class InvalidFileException extends \RuntimeException
{
}