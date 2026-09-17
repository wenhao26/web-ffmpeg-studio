<?php
declare(strict_types=1);

/**
 * ============================================================================
 * FFmpeg/FFprobe 全局配置
 * ============================================================================
 *
 * 本文件返回 Web FFmpeg Studio 后端所需的全部 FFmpeg 相关配置，
 * 是整个 Phase 1~3 安全模型的配置底座（不在此文件内写业务逻辑）。
 *
 * 目录规划:
 *   config/ffmpeg.php  (本文件)  — 二进制路径 / 进程 / 存储 / 上传 / 清理 / 白名单
 *
 * 配置来源优先级（由高到低）:
 *   1. 环境变量 FFMPEG_BIN / FFPROBE_BIN   —— 容器与 CI 场景覆盖为系统二进制
 *   2. 项目内置二进制 bin/ffmpeg/{os}       —— 随仓库分发的静态编译产物
 *
 * 依赖关系:
 *   - BinaryChecker   读取 [binaries]，校验后可执行性
 *   - InputSanitizer  读取 [whitelist]，对动态参数做白名单约束
 *   - ProcessRunner   读取 [process.timeout]，作为硬性超时上限
 *   - Phase 2 控制器  读取 [upload] / [storage]，做上传路由与落盘校验
 *   - Phase 3 定时清理 读取 [cleanup]，决定孤儿文件生命周期
 * ============================================================================
 */

/* ---- 1. 路径推导：按宿主平台定位二进制与存储根目录 ---------------------- */
// dirname(__DIR__) 即 backend/；再上一层即项目根目录，保证从任意 cwd 运行都稳定。
$backendDir = dirname(__DIR__);
$projectDir = dirname($backendDir);

// PHP_OS_FAMILY：Windows 返回 'Windows'，其余常见发行版为 'Linux'。
// 二进制静态编译产物按操作系统分目录存放，Windows 前缀为 .exe。
$osDir = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
$ext   = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

$binaryDir = $projectDir . '/bin/ffmpeg/' . $osDir;

// 环境变量覆盖：getenv() 未命中时返回 false，
// 命中但为空串时同样视为未设置，退回内置二进制目录。
$envFfmpeg  = getenv('FFMPEG_BIN');
$envFfprobe = getenv('FFPROBE_BIN');

return [

    /* ---- 2. 二进制路径 -------------------------------------------------- */
    'binaries' => [
        'ffmpeg'  => $envFfmpeg  !== false && $envFfmpeg  !== '' ? $envFfmpeg  : $binaryDir . '/ffmpeg' . $ext,
        'ffprobe' => $envFfprobe !== false && $envFfprobe !== '' ? $envFfprobe : $binaryDir . '/ffprobe' . $ext,
    ],

    /* ---- 3. 进程执行约束 ------------------------------------------------ */
    // 硬性超时上限（秒）：与上传 1GB 上限配套，防止极端耗时的转码任务
    // 无限占用 worker，ProcessRunner 在超时后强制 kill 子进程树。
    'process' => [
        'timeout' => 300,
    ],

    /* ---- 4. 存储分区规划 ------------------------------------------------ */
    // 三区隔离：uploads 输入的临时保留区 / outputs 成果区（提供给前端下载）/
    // temp 超长任务（如 HLS 多段切片）的工作目录。三区 TTL 见下方 cleanup。
    'storage' => [
        'inputs'  => $projectDir . '/storage/inputs',
        'outputs' => $projectDir . '/storage/outputs',
        'temp'    => $projectDir . '/storage/temp',
    ],

    /* ---- 5. 上传限制 ---------------------------------------------------- */
    // 单位字节：1073741824 B = 1 GiB，对应 prd 关于大文件素材的目标。
    'upload' => [
        'max_size' => 1073741824,
    ],

    /* ---- 6. 文件生命周期清理（秒，TTL） --------------------------------- */
    // 输入 24h / 输出 72h / 临时 1h：输入与临时区加速回收，
    // 输出区给用户留足下载缓冲期，由 Phase 3 清理任务消费。
    'cleanup' => [
        'inputs_ttl'  => 86400,
        'outputs_ttl' => 259200,
        'temp_ttl'    => 3600,
    ],

    /* ---- 7. 安全白名单 --------------------------------------------------- */
    // 所有进入 CommandBuilder 的动态值都必须落在下列集合内，
    // Sanitizer 逐项校验，防止参数注入（如 -filters、;-shell 拼凑恶意命令）。
    'whitelist' => [
        'video_codecs' => ['libx264', 'libx265', 'libvpx-vp9', 'libaom-av1'],
        'audio_codecs' => ['aac', 'libmp3lame', 'libopus'],
        'formats'      => ['mp4', 'webm', 'mkv', 'mov', 'hls'],
        'presets'      => [
            'ultrafast', 'superfast', 'veryfast', 'faster',
            'fast', 'medium', 'slow', 'slower', 'veryslow',
        ],
        'positions' => [
            'top-left', 'top-right', 'bottom-left',
            'bottom-right', 'center',
        ],
        // 上传步骤校验 MIME 类型，与项目需求 docs/项目需求.md 中列出的
        // 支持素材清单一致；不允许的 mime 直接在路由层拒绝。
        'mime_types' => [
            'video/mp4',
            'video/webm',
            'video/x-matroska',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-flv',
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            'audio/x-flac',
            // 图片素材：仅用于图片水印（watermark_type=image）的叠加源。
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
        ],
    ],

];