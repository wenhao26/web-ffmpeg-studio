<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidArgumentException;

/**
 * ============================================================================
 * CommandBuilder — FFmpeg 命令拼装服务
 * ============================================================================
 *
 * 核心职责:
 *   将 InputSanitizer 校验通过的“干净参数”按 FFmpeg CLI 语法序列化为
 *   一行完整命令，交付 ProcessRunner 执行。它是“参数 → 命令”的
 *   最后一道安全关口，所有动态 token 在此统一 escapeshellarg()。
 *
 * 安全模型（两段转义）:
 *   - shell 层:      每个出现在最终命令中的动态值 100% 经 escapeshellarg()
 *                    （Windows 下 Symfony 包装 cmd，quote 规则兼容）。
 *   - filter 层:     drawtext/overlay 这类 filter_complex 内部取值有独立的
 *                    转义需求（: , ' \），在各自方法内做第二段转义。
 *   - 专家参数:      custom_args 逐 token 解析，仅放行 CUSTOM_TOKEN_WHITELIST
 *                    内的白名单开关，其余一律拒绝执行（宁可功能受限不可失守）。
 *
 * 输出约定（硬性）:
 *   - 命令以 ffmpeg 绝对路径 + -y（覆盖同名输出）开头；
 *   - 输入段在开销最小的 -i 之前，-y 后紧随；
 *   - custom_args 严格插在输出文件参数之前（Phase 1 修复过的坑：
 *     放在输出文件之后的选项会被 ffmpeg 忽略或误判为下一输出）。
 *
 * 依赖注入:
 *   CommandBuilder 持有 InputSanitizer 实例 + 已通过 BinaryChecker
 *   校验的 ffmpeg 路径，由控制器/工厂组装，职责不扩散。
 * ============================================================================
 */
class CommandBuilder
{
    /**
     * 专家模式允许的 FFmpeg 选项名白名单。
     *
     * 授信原则：仅收录“对媒体流本身做参数化调节、无路径/无脚本语义”
     * 的开关。以下类目严禁出现在列表中：
     *   - 输入类：-i / -f（可指向任意文件）
     *   - 过滤器链：-vf / -filter_complex / -af
     *   - 输出重定向/脚本：; | & < > `` $()
     *   - 协议类：-protocol_whitelist 的可写扩展
     *
     * 值部分不受白名单约束，但会被 escapeshellarg() 包裹，
     * 因此即使值被伪造也不会突破为多个 shell token。
     */
    private const CUSTOM_TOKEN_WHITELIST = [
        '-threads',
        '-pix_fmt',
        '-max_muxing_queue_size', // 大文件转码关键：防止 muxing 写入排队上限导致卡死
        '-movflags',
        '-vsync',   // 帧同步策略（cfr/vfr/passthrough）
        '-r',       // 输出帧率（配合 -vsync cfr）
        '-g',       // GOP 大小
        '-bf',      // B 帧数量
        '-sc_threshold',
        '-keyint_min',
        '-aq-mode',
        '-deadline', // libvpx 实时/离线模式
        '-cpu-used',
        '-row-mt',
        '-tile-columns',
        '-tile-rows',
        '-x265-params',
        '-svtav1-params',
        '-pix_fmts', // 查询类（不产生副作用）
        '-map_metadata',
        '-metadata:s:v',
        '-metadata:s:a',
    ];

    private InputSanitizer $sanitizer;

    private string $binaryPath;

    /**
     * @param InputSanitizer $sanitizer  已就绪的入参清洗器
     * @param string         $binaryPath BinaryChecker::checkFfmpeg() 的校验结果
     */
    public function __construct(InputSanitizer $sanitizer, string $binaryPath)
    {
        $this->sanitizer   = $sanitizer;
        $this->binaryPath  = $binaryPath;
    }

    /**
     * 构建完整 FFmpeg 命令行。
     *
     * @param array<string, mixed> $params 未清洗的原始请求参数
     *
     * @return string 可交付 ProcessRunner 执行的完整命令行
     *
     * @throws InvalidArgumentException 参数不合法或专家参数触犯白名单
     */
    public function build(array $params): string
    {
        // 0) 先清洗：只保留通过白名单/格式校验的字段。
        $clean = $this->sanitizer->sanitize($params);

        // 1) 命令启动头：二进制绝对路径 + -y（Phase 1 实测：无 -y 时
        //    复用同名输出文件会触发 ffmpeg 交互式覆盖询问，导致
        //    无人值守场景 exit=-17 卡死；服务端输出路径由生成器唯一化，覆盖安全）。
        $command = escapeshellarg($this->binaryPath) . ' -y';

        // 2) 输入段：-i 仅接受服务端生成的路径（控制器层保证），
        //    此处仍统一转义以抵御任何绕过路径的拼接。
        if (isset($clean['input_path']) && $clean['input_path'] !== '') {
            $command .= ' -i ' . escapeshellarg((string) $clean['input_path']);
        }

        // 图片水印的第二输入流必须紧随主输入声明，且早于任何输出选项。
        // 反例（曾导致 "Decoder not found"）：把 -i watermark.png 放到
        // -c:v/-crf/-preset 之后，ffmpeg 会把这些输出选项当作第二个输入的
        // input option，进而用 libx264 去解码 PNG。
        if (isset($clean['watermark_image']) && $clean['watermark_image'] !== '') {
            $command .= ' -i ' . escapeshellarg((string) $clean['watermark_image']);
        }

        // 3) 基础流选项（编码器 / 码率 / 预设 / 剪切时间）。
        $command .= $this->buildBasicOptions($clean);

        // 4) 滤镜段（裁剪 / 缩放 / 文字水印走 -vf；图片水印走 filter_complex）。
        $command .= $this->buildFilterSection($clean);

        // 5) 专家参数：必须插在输出文件之前（见类注释中的顺序安全说明）。
        if (isset($clean['custom_args'])) {
            $command .= $this->buildCustomArgs((string) $clean['custom_args']);
        }

        // 6) 输出段：HLS 走分片协议，其他格式走普通输出路径。
        if (($clean['output_format'] ?? '') === 'hls') {
            $command .= $this->buildHlsSection($clean);
        } else {
            $command .= $this->buildOutputSection($clean);
        }

        return $command;
    }

    /**
     * 拼装基础流选项：编码器 / CRF / 码率 / 预设 / 音频 / 剪切时间。
     *
     * 全部为“空格分隔的独立 token 对”（选项 + escapeshellarg 值），
     * 空字段由 sanitize 已剔除，此处仅需判断 isset/非空。
     *
     * @param array<string, mixed> $clean 已清洗参数
     */
    private function buildBasicOptions(array $clean): string
    {
        $parts = [];

        if (!empty($clean['video_codec'])) {
            $parts[] = '-c:v ' . escapeshellarg((string) $clean['video_codec']);
        }
        if (array_key_exists('crf', $clean) && $clean['crf'] !== '') {
            // CRF 已是 Int（sanitize 强转 + 区间校验），无需引号。
            $parts[] = '-crf ' . (int) $clean['crf'];
        }
        if (!empty($clean['video_bitrate'])) {
            $parts[] = '-b:v ' . escapeshellarg((string) $clean['video_bitrate']);
        }
        if (!empty($clean['preset'])) {
            $parts[] = '-preset ' . escapeshellarg((string) $clean['preset']);
        }
        if (!empty($clean['audio_codec'])) {
            $parts[] = '-c:a ' . escapeshellarg((string) $clean['audio_codec']);
        }
        if (!empty($clean['audio_bitrate'])) {
            $parts[] = '-b:a ' . escapeshellarg((string) $clean['audio_bitrate']);
        }

        // 时间剪切：-ss 放 -i 之后做“精确 seek”（相对推荐做法），
        // -to 为输出截止时间，二者组合表达“从 X 到 Y 的片段”。
        if (!empty($clean['start_time'])) {
            $parts[] = '-ss ' . escapeshellarg((string) $clean['start_time']);
        }
        if (!empty($clean['end_time'])) {
            $parts[] = '-to ' . escapeshellarg((string) $clean['end_time']);
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * 滤镜段路由：
     *   - 无图片水印 → 单一 -vf 链（crop,scale[,drawtext]）
     *   - 有图片水印 → filter_complex（需要第二输入流，见 buildImageWatermarkSection）
     *
     * 注意 ffmpeg 中 -vf 与 -filter_complex 会冲突，必须二选一，
     * 因此以是否有 watermark_image 为分界线分别拼装。
     *
     * @param array<string, mixed> $clean 已清洗参数
     */
    private function buildFilterSection(array $clean): string
    {
        if (!isset($clean['watermark_image'])) {
            // >>> 无图水印：普通 -vf 链 <<<
            $filters = $this->collectVideoFilters($clean);

            $drawtext = $this->buildDrawTextFilter(
                $clean['watermark_text'] ?? '',
                (string) ($clean['watermark_position'] ?? '')
            );
            if ($drawtext !== '') {
                $filters[] = $drawtext;
            }

            // 整条 filter 链作为单个 shell token 转义（内部逗号是 ffmpeg 语法而非 shell 分隔符）。
            return $filters === [] ? '' : ' -vf ' . escapeshellarg(implode(',', $filters));
        }

        return $this->buildImageWatermarkSection($clean);
    }

    /**
     * 收集基础视频滤镜（crop / scale）。
     *
     * 值为 sanitize 已正则校验的几何串，直接拼接进滤镜链；
     * 不在此处 escapeshellarg，因为整个 filter 链最后被整体转义。
     *
     * @param array<string, mixed> $clean
     *
     * @return string[] 滤镜片段集合（crop=… / scale=…）
     */
    private function collectVideoFilters(array $clean): array
    {
        $filters = [];

        if (!empty($clean['crop'])) {
            $filters[] = 'crop=' . $clean['crop'];
        }
        if (!empty($clean['scale'])) {
            // scale= 使用 冒号 分隔宽高；若日后需要“等比缩放到 <= 目标
            // 尺寸”可改为 scale=w:h:force_original_aspect_ratio=decrease。
            $filters[] = 'scale=' . $clean['scale'];
        }

        return $filters;
    }

    /**
     * 图片水印：第二输入流 + filter_complex。
     *
     * FFmpeg 滤镜图语义（第二输入 -i 已在 build() 的输入段声明）:
     *   [0:v] = 主输入视频流
     *   [1:v] = 水印图片流
     *   带基础裁剪/缩放时：先 [0:v]crop,scale[base]，再叠加
     *   [1:v][base]overlay=x:y -> [out]
     *   无基础滤镜时：直接 [0:v][1:v]overlay=x:y -> [out]
     *   （注意：[base] 只在确实定义了基础链时才可被引用，否则 ffmpeg
     *    报 "Error linking filters / Invalid argument"）。
     *   最后 -map [out]（主画面）与 -map 0:a?（音频，可选）。
     *
     * 位置坐标通过 main_w/main_h（主视频）与 overlay_w/overlay_h（水印）
     * 的表达式计算，保证贴边 10px 且在真尺寸下生效。
     *
     * @param array<string, mixed> $clean
     */
    private function buildImageWatermarkSection(array $clean): string
    {
        // 1) 按角点位置解析 x/y 表达式。
        $x = $this->resolveOverlayX((string) ($clean['watermark_position'] ?? 'bottom-right'));
        $y = $this->resolveOverlayY((string) ($clean['watermark_position'] ?? 'bottom-right'));

        // 2) 基础滤镜切片：(可选) crop/scale，拼进主链。
        $baseFilter = implode(',', $this->collectVideoFilters($clean));

        // 3) 组装滤镜图：无基础链时 [base] 不存在，必须走两输入 overlay。
        if ($baseFilter === '') {
            $graph = '[0:v][1:v]overlay=' . $x . ':' . $y . '[out]';
        } else {
            $graph = '[0:v]' . $baseFilter . '[base];[1:v][base]overlay=' . $x . ':' . $y . '[out]';
        }

        // 4) 用 -map 显式选择输出流（图片流本身不落盘）。
        return ' -filter_complex ' . escapeshellarg($graph)
             . ' -map [out] -map 0:a?';
    }

    /**
     * 文字水印 drawtext 滤镜构造。
     *
     * 第二段转义（filter 语境内）：filter 参数以 ':' 分隔、
     * 选项以 '=' 连接，且 drawtext 解析器对 '\\'、':'、','、引号
     * 有自己的转义语法（前置反斜杠），须在 shell 转义之前完成。
     *
     * @param string $text     用户水印文本（未经任何转义的原始值）
     * @param string $position 角点位置（whitelist.positions）
     *
     * @return string drawtext=… 滤镜片段；文本为空时返回 ''
     */
    private function buildDrawTextFilter(string $text, string $position): string
    {
        if ($text === '') {
            return '';
        }

        // 1) filter 语法内转义：反斜杠→\\、冒号→\:、逗号→\、
        //    单引号→\'（ffmpeg filter 语法的转义，非 shell 语法），
        //    保持文本字面含义。
        $escapedText = str_replace(
            ['\\', ':', ',', "'"],
            ['\\\\', '\:', '\,', "\\'"],
            $text
        );

        // 2) 角点坐标；text_w/text_h 为实际渲染后文本尺寸（放在 ffmpeg
        //    表达式内求值，可自适应绘图区域对齐）。
        [$x, $y] = $this->resolveTextCoord($position);

        // 3) 固定字体样式（24px、白色 80% 透明）。
        //    expansion=none 关闭 %{...} 文本展开，用户文本按字面渲染，
        //    避免文本含 % 时被 drawtext 当作表达式解析而报错。
        return 'drawtext=text=' . $escapedText . ':x=' . $x . ':y=' . $y
             . ':fontsize=24:fontcolor=white@0.8:fontfile=' . $this->resolveFontPath()
             . ':expansion=none';
    }

    /**
     * 返回 drawtext 使用的字体路径。
     *
     * 优先选用同时覆盖中文与拉丁字符的 Microsoft YaHei（msyh.ttc），
     * 缺失时回退 Arial。中文水印用 Arial 会渲染成方框（缺字形）。
     *
     * 关键：盘符后的冒号在 ffmpeg filter 语法里必须转义成 `\\:`（两个
     * 反斜杠），否则解析器会在 `C:` 处断开并报
     * "No option name near '/Windows/Fonts/...'"。只写一个反斜杠
     * （`C\:`）实测仍会被吃掉，故此处刻意保留双反斜杠。
     *
     * TODO(Phase Cross)：Linux 部署需实现平台字体探测（如 fc-list）。
     */
    private function resolveFontPath(): string
    {
        $candidates = [
            'C:/Windows/Fonts/msyh.ttc',   // 微软雅黑（含 CJK）
            'C:/Windows/Fonts/simhei.ttf', // 黑体（含 CJK）
            'C:/Windows/Fonts/arial.ttf',  // 纯拉丁兜底
        ];

        foreach ($candidates as $font) {
            if (@is_file($font)) {
                return $this->escapeFontPath($font);
            }
        }

        return $this->escapeFontPath('C:/Windows/Fonts/arial.ttf');
    }

    /**
     * 将 Windows 字体绝对路径转为 drawtext 可解析的形式：
     * 统一正斜杠，并把盘符冒号转义为 `\\:`（双反斜杠 + 冒号）。
     */
    private function escapeFontPath(string $path): string
    {
        return str_replace(':', '\\\\:', str_replace('\\', '/', $path));
    }

    /**
     * 文字水印坐标：无尺寸计算时固定边距 10px；居中时用
     * (w-text_w)/2 表达式让 ffmpeg 在运行期求值对齐。
     *
     * @return array{string, string} [x, y] 表达式
     */
    private function resolveTextCoord(string $position): array
    {
        return match ($position) {
            'top-left'             => ['10', '10'],
            'top-right'            => ['w-text_w-10', '10'],
            'bottom-left'          => ['10', 'h-text_h-10'],
            'center'               => ['(w-text_w)/2', '(h-text_h)/2'],
            default                => ['w-text_w-10', 'h-text_h-10'], // bottom-right 兜底
        };
    }

    /**
     * 图片水印水平坐标（main_w/overlay_w 表达式）。
     */
    private function resolveOverlayX(string $position): string
    {
        return match ($position) {
            'top-left', 'bottom-left' => '10',
            'top-right', 'bottom-right' => 'main_w-overlay_w-10',
            'center' => '(main_w-overlay_w)/2',
            default => 'main_w-overlay_w-10',
        };
    }

    /**
     * 图片水印垂直坐标（main_h/overlay_h 表达式）。
     */
    private function resolveOverlayY(string $position): string
    {
        return match ($position) {
            'top-left', 'top-right' => '10',
            'bottom-left', 'bottom-right' => 'main_h-overlay_h-10',
            'center' => '(main_h-overlay_h)/2',
            default => 'main_h-overlay_h-10',
        };
    }

    /**
     * 普通输出文件段（非 HLS）。
     *
     * output_path 由控制器基于 FileId 生成唯一落盘路径，在此仅做
     * token 级转义，不负责目录存在性（FileCollector/上传服务保证）。
     *
     * @param array<string, mixed> $clean
     */
    private function buildOutputSection(array $clean): string
    {
        if (empty($clean['output_path'])) {
            return '';
        }

        return ' ' . escapeshellarg((string) $clean['output_path']);
    }

    /**
     * HLS 分片输出段。
     *
     * 生成 VOD 型 HLS：输出目录为 output_base_path，切片 6 秒一段、
     * 命名为 segment_%04d.ts，最后生成 output.m3u8 播放清单。
     *
     * @param array<string, mixed> $clean
     *
     * @throws InvalidArgumentException HLS 未提供输出目录时抛错（前置校验）
     */
    private function buildHlsSection(array $clean): string
    {
        $basePath = (string) ($clean['output_base_path'] ?? '');

        if ($basePath === '') {
            throw new InvalidArgumentException('生成 HLS 需要提供输出目录 (output_base_path)');
        }

        // -f hls 强制封装为 HLS；-hls_time 6 每段时长（秒）；
        // -hls_playlist_type vod 输出点播型 playlist（去 EXT-X-ENDLIST 语义由 ffmpeg 管理）。
        //
        // 注意：segment 文件名含 %04d 分片序号占位符，绝不能走 escapeshellarg——
        // Windows 版 escapeshellarg 会把 % 替换成空格，占位符被破坏后 hls muxer
        // 直接报 "Could not write header ... Invalid argument"。basePath 由服务端
        // 基于 UUID 生成（非用户输入），置于双引号内即安全；cmd 命令行上 %04d
        // 不构成变量展开（仅 %VAR% 形式才会展开）。
        $segmentPattern = $basePath . '/segment_%04d.ts';

        return ' -f hls -hls_time 6 -hls_playlist_type vod'
             . ' -hls_segment_filename "' . $segmentPattern . '"'
             . ' ' . escapeshellarg($basePath . '/output.m3u8');
    }

    /**
     * 专家模式自定义参数白名单解析。
     *
     * 解析算法（不支持括号内嵌参数，保持严格）：
     *   1. 按空白拆成 token 数组；
     *   2. 每个 token 必须形如 /^-{1,2}[a-zA-Z0-9:_\[\]-]+$/，否则拒绝；
     *   3. token 必须命中 CUSTOM_TOKEN_WHITELIST；
     *   4. 紧随其后的非开关 token 作为其值，escapeshellarg 包裹。
     *
     * 这样“-vf drawtext=..." 这类带 filter 链的侵入参数、
     * “; rm -rf” 这类 shell 注入串都会在步骤 2/3 被拦死。
     *
     * @throws InvalidArgumentException 含未授权参数开关时拒绝执行
     */
    private function buildCustomArgs(string $customArgs): string
    {
        $tokens = preg_split('/\s+/', trim($customArgs));
        if ($tokens === false) {
            return '';
        }

        $result = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 形状校验：只允许纯参数开关形态（短/长选项），拒绝含空格、
            // 引号、分号、管道等可能改变 token 语义的字符。
            if (!preg_match('/^-{1,2}[a-zA-Z0-9:_\[\]-]+$/', $token)) {
                throw new InvalidArgumentException("专家参数包含非法 token: '{$token}'");
            }

            // 语义校验：开关必须命中白名单。
            if (!in_array($token, self::CUSTOM_TOKEN_WHITELIST, true)) {
                throw new InvalidArgumentException("专家参数开关 {$token} 不在白名单内，已拒绝");
            }

            $result[] = $token;

            // 值兜底：若后一 token 不是“新开关”（不含前导 -），视为当前
            // 开关的值并整体 escapeshellarg；否则说明该开关无值，继续。
            if (isset($tokens[$i + 1]) && !preg_match('/^-{1,2}[a-zA-Z0-9:_\[\]-]+$/', $tokens[$i + 1])) {
                $result[] = escapeshellarg($tokens[++$i]);
            }
        }

        return ' ' . implode(' ', $result);
    }
}