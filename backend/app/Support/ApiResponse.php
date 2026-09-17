<?php
declare(strict_types=1);

namespace App\Support;

use support\Response;

/**
 * ============================================================================
 * ApiResponse — 统一 JSON 响应契约封装
 * ============================================================================
 *
 * 全项目 HTTP 出口统一使用本类，保证前端 Axios 拦截器只面对一种结构：
 *
 *   {
 *     "code":    200,           // 业务码：200 成功，4xx 参数/资源错误，5xx 服务错误
 *     "message": "success",      // 人类可读描述
 *     "data":    { ... }         // 载荷（成功时可为空数组，失败时可携带上下文）
 *   }
 *
 * 与全局异常处理器（support/exception/Handler）配合：控制器只抛业务异常，
 * 状态码与消息的 HTTP 化由右侧工厂方法在此集中完成，禁止各控制器自拼结构。
 *
 * 设计约定:
 *   - success()：HTTP 200 + 业务 code 200；
 *   - error()：  HTTP 状态码与业务 code 保持一致（400/404/413/422/500/504 ...），
 *                便于前端 useApi 拦截器按 code 直接分支。
 * ============================================================================
 */
final class ApiResponse
{
    /**
     * 成功响应。
     *
     * @param mixed  $data    业务载荷（数组/对象/null）
     * @param string $message 成功描述，默认 "success"
     */
    public static function success(mixed $data = [], string $message = 'success'): Response
    {
        return self::json(200, $message, $data, 200);
    }

    /**
     * 失败响应。
     *
     * @param string     $message    错误描述（应可直接展示给用户）
     * @param int        $code       业务码 / HTTP 状态码（默认 500）
     * @param mixed      $data       可选的失败上下文（如非法字段明细）
     * @param int|null   $httpStatus 显式指定 HTTP 状态码；null 时与 $code 相同
     */
    public static function error(
        string $message,
        int $code = 500,
        mixed $data = null,
        ?int $httpStatus = null
    ): Response {
        return self::json($code, $message, $data, $httpStatus ?? $code);
    }

    /**
     * 统一 JSON 响应构造（注意：data 为 null 时返回 JSON null 而非缺省字段，
     * 保证契约字段完整性）。
     *
     * @param int        $code       业务码
     * @param string     $message    消息
     * @param mixed      $data       载荷
     * @param int        $httpStatus HTTP 状态码
     */
    private static function json(int $code, string $message, mixed $data, int $httpStatus): Response
    {
        // json() 全局 helper 不支持 status 参数，落地后通过 withStatus 设置
        // HTTP 状态码（业务 code 在 data.code 字段独立表达，与 HTTP 解耦）。
        $response = json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response->withStatus($httpStatus);
    }
}