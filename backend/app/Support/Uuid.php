<?php
declare(strict_types=1);

namespace App\Support;

/**
 * ============================================================================
 * Uuid — 轻量 UUID v4 生成器
 * ============================================================================
 *
 * 依赖 PHP 8.2+ 的 random（random_bytes），不依赖第三方包，
 * 用于 file_id / task_id 的唯一标识生成。
 *
 * 说明:
 *   - 当前宿主 PHP 无 openssl 扩展，random_bytes() 由 core random 扩展提供，
 *     因此本实现可稳定运行（生产环境同样可用）。
 * ============================================================================
 */
final class Uuid
{
    /**
     * 生成 RFC 4122 Version 4 随机 UUID。
     *
     * @return string 形如 "6ba7b810-9dad-11d1-80b4-00c04fd430c8"（小写）
     */
    public static function v4(): string
    {
        // 16 字节（128 bit）真随机源。
        $bytes = random_bytes(16);

        // 设置 version=4（第 7 个字节高 4 位为 0100）…
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // …以及 variant=10 位（第 9 个字节高 2 位为 10）。
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        // 按 RFC 分段成 8-4-4-4-12 结构。
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}