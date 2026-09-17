<?php
declare(strict_types=1);

namespace support\exception;

use App\Exception\BinaryNotFoundException;
use App\Exception\ExecuteException;
use App\Exception\InvalidArgumentException;
use App\Exception\InvalidFileException;
use App\Exception\ProcessTimeoutException;
use App\Exception\ProbeException;
use App\Support\ApiResponse;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * ============================================================================
 * 全局异常处理器
 * ============================================================================
 *
 * 覆盖 webman 默认 Handler（config/exception.php 指向本类），
 * 统一完成「异常 → HTTP 状态码 + 契约 JSON」的映射，避免 controller
 * 各自 try/catch：
 *
 *   | 异常类型                     | HTTP | 业务 code | 语义                        |
 *   |------------------------------|------|-----------|-----------------------------|
 *   | InvalidArgumentException     | 400  | 400       | 参数不合法（表单可修正）      |
 *   | InvalidFileException         | 400  | 400       | 上传文件校验失败             |
 *   | BinaryNotFoundException      | 500  | 500       | 二进制缺失（运维错误）        |
 *   | ProbeException               | 422  | 422       | ffprobe 探测失败 / 无媒体流  |
 *   | ProcessTimeoutException      | 504  | 504       | 进程硬性超时                 |
 *   | ExecuteException             | 500  | 500       | ffmpeg 执行失败              |
 *   | 其他                         | 500  | 500       | 兜底（保留框架默认行为）      |
 *
 * dontReport：上述业务异常都属于「可预期、已处理」场景，
 * 不进入 error log，避免刷屏；infra 级异常仍走 parent::report。
 * ============================================================================
 */
class Handler extends ExceptionHandler
{
    /**
     * 无需上报的异常类型（业务预期内，不在日志刷堆栈）。
     *
     * @var class-string[]
     */
    public $dontReport = [
        InvalidArgumentException::class,
        InvalidFileException::class,
        BinaryNotFoundException::class,
        ProbeException::class,
        ProcessTimeoutException::class,
        ExecuteException::class,
        \Webman\Exception\BusinessException::class,
    ];

    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * 渲染为 HTTP 响应：业务异常 → 契约 JSON，其余交回框架默认。
     */
    public function render(Request $request, Throwable $exception): Response
    {
        // ---- 业务参数异常：表单回显友好信息（4xx） ----
        if ($exception instanceof InvalidArgumentException) {
            return ApiResponse::error($exception->getMessage(), 400);
        }

        if ($exception instanceof InvalidFileException) {
            return ApiResponse::error($exception->getMessage(), 400);
        }

        // ---- 探测失败：附带上下文便于诊断 ----
        if ($exception instanceof ProbeException) {
            return ApiResponse::error($exception->getMessage(), 422, [
                'probe_context' => $exception->getContext(),
            ]);
        }

        // ---- 二进制缺失：配置/运维错误，直接 5xx ----
        if ($exception instanceof BinaryNotFoundException) {
            return ApiResponse::error($exception->getMessage(), 500);
        }

        // ---- 进程超时：网关超时语义 ----
        if ($exception instanceof ProcessTimeoutException) {
            return ApiResponse::error($exception->getMessage(), 504);
        }

        // ---- 执行失败：携带命令现场便于前端展示 ----
        if ($exception instanceof ExecuteException) {
            return ApiResponse::error($exception->getMessage(), 500, [
                'execute_context' => $exception->getContext(),
            ]);
        }

        // ---- 兜底：走框架默认（HTML/JSON 自适应） ----
        return parent::render($request, $exception);
    }
}