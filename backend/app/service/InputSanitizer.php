<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidArgumentException;

/**
 * ============================================================================
 * InputSanitizer — 动态参数清洗与白名单校验服务
 * ============================================================================
 *
 * 安全定位:
 *   FFmpeg CLI 的每一个动态 token 都是潜在的注入点，
 *   本服务在业务语义层（枚举 / 数值区间 / 格式正则）终结攻击面，
 *   而不依赖 shell 层转义兜底。
 *
 * 职责边界（三阶段职责分离）:
 *   1. InputSanitizer::sanitize()  → 校验“值合法”      （本文件）
 *   2. CommandBuilder::build()      → 处理“序列化安全”  （escapeshellarg）
 *   3. ProcessRunner::execute()     → 执行“沙箱 + 超时” （Symfony Process）
 *
 * 关键设计决策:
 *   - sanitize() 返回“已校验但未转义”的干净值：因为转义时机
 *     必须由 Builder 决定——同一字段在 shell 层（-c:v 值）与
 *     filter_complex 内部语义（drawtext 文本）需要截然不同的转义规则。
 *   - 空串字段默认放行并随后剔除（filterBlankFields），使“用户未传”
 *     与“用户传空”在 Builder 层行为一致，避免空 token 进入命令。
 *   - 本服务不做目录穿越/路径校验——input_path/output_path 由控制器
 *     服务端生成，不属于外部输入（见 Phase 2 上传控制器说明）。
 *
 * 调用方: CommandBuilder 唯一消费者；Phase 2 探测/转码 controller 间接消费。
 * ============================================================================
 */
class InputSanitizer
{
    /**
     * 时间格式：HH:MM:SS 或 HH:MM:SS.mmm（毫秒至 3 位）。
     * ffmpeg 官方时间戳语法，误传负值与裸秒串将被拦截。
     */
    private const TIME_PATTERN = '/^\d{2}:\d{2}:\d{2}(\.\d{1,3})?$/';

    /**
     * 码率格式：1~9 位数字，可带整数单位 k/K/m/M/g/G（ffmpeg 兼容大小写）。
     */
    private const BITRATE_PATTERN = '/^\d{1,9}[kKmMgG]?$/';

    /**
     * crop 裁剪矩形：w:h 或 w:h:x:y，各分量 1~6 位数字。
     * 命中后可直接进入 crop= 滤镜参数，配合 “:” 语义。
     */
    private const RECT_PATTERN = '/^\d{1,6}:\d{1,6}(:\d{1,6}:\d{1,6})?$/';

    /**
     * CRF 输入控件的合法闭区间 [0,51]（x264/x265 标准范围）。
     * 0 为无损，51 为最差质量；整数溢出风险在 (int) 强转后落入该检查。
     */
    private const CRF_MIN = 0;

    private const CRF_MAX = 51;

    /**
     * 文本型字段（水印文字 / 专家参数）最大长度，防御超大 payload。
     */
    private const TEXT_MAX_LENGTH = 500;

    /**
     * config/ffmpeg.php 的 [whitelist] 段引用。
     *
     * @var array{ video_codecs: string[], audio_codecs: string[], formats: string[], presets: string[], positions: string[], mime_types: string[] }
     */
    private array $whitelist;

    /**
     * @param array $config config/ffmpeg.php 的全部配置数组
     */
    public function __construct(array $config)
    {
        $this->whitelist = $config['whitelist'];
    }

    /**
     * 清洗并校验 FFmpeg 执行参数，返回可安全拼装的干净值集合。
     *
     * 校验顺序约定（自上而下）：
     *   必填枚举 → 可选枚举 → 数值区间 → 时间格式 → 数值格式 → 文本长度。
     *   任一失败立即抛 InvalidArgumentException 并附带可读原因，
     *   便于控制器转为统一 JSON 错误结构。
     *
     * @param array<string, mixed> $params 原始请求参数（POST JSON 解析结果）
     *
     * @return array<string, mixed> 至少通过白名单/格式校验、且非空的字段子集
     *
     * @throws InvalidArgumentException 任一字段不满足白名单或格式约束
     */
    public function sanitize(array $params): array
    {
        // >>> 第 1 类字段：必填的单一格式枚举 <<<
        // output_format 决定后续走普通输出还是 HLS 分片，缺失时
        // assertEnum 携 required=true 抛“必填项”错误。
        $this->assertEnum('output_format', (string) ($params['output_format'] ?? ''), $this->whitelist['formats'], true);

        // >>> 第 2 类字段：可选的离散枚举 <<<
        // 键值对表驱动：键为字段名，值为各自白名单；
        // 后续新增白名单项（如 nvenc 编码器）只需改 config 与这里两张表。
        $enumFields = [
            'video_codec'        => $this->whitelist['video_codecs'],
            'audio_codec'        => $this->whitelist['audio_codecs'],
            'preset'             => $this->whitelist['presets'],
            'watermark_position' => $this->whitelist['positions'],
            // watermark_type 为含枚举语义的开关（image/text），不构造成本。
            'watermark_type'     => ['image', 'text'],
        ];
        foreach ($enumFields as $field => $allowed) {
            $this->assertEnum($field, (string) ($params[$field] ?? ''), $allowed);
        }

        // >>> 第 3 类字段：数值区间（CRF）<<<
        // crf 允许省略（libx264 用默认 23），显式传入空串视为未设置。
        // (int) 强转在 PHP 宽松模式下吸收 '23abc' 之类噪声，但越界由 assertRange 拦截。
        if (isset($params['crf']) && $params['crf'] !== '') {
            $this->assertRange('crf', (int) $params['crf'], self::CRF_MIN, self::CRF_MAX);
        }

        // >>> 第 4 类字段：时间戳语法 <<<
        // start_time 用于精确剪辑起止；end_time 映射为 -to 而非 -t（见 CommandBuilder）。
        $this->assertTime('start_time', (string) ($params['start_time'] ?? ''));
        $this->assertTime('end_time', (string) ($params['end_time'] ?? ''));

        // >>> 第 5 类字段：数字+可选单位的字符串格式（码率 / 几何）<<<
        // video/audio_bitrate：例如 "2M"、"800k"；
        // crop：w:h 或 w:h:x:y；scale：仅 w:h（与 ffmpeg scale= 语法对齐）。
        $this->assertPattern('video_bitrate', (string) ($params['video_bitrate'] ?? ''), self::BITRATE_PATTERN);
        $this->assertPattern('audio_bitrate', (string) ($params['audio_bitrate'] ?? ''), self::BITRATE_PATTERN);
        $this->assertPattern('crop', (string) ($params['crop'] ?? ''), self::RECT_PATTERN);
        $this->assertPattern('scale', (string) ($params['scale'] ?? ''), '/^\d{1,6}:\d{1,6}$/');

        // >>> 第 6 类字段：文本长度上限 <<<
        // 水印文字与专家参数都可能在 filter_complex 内被二次展开，
        // 限制长度同时抑制畸形嵌套导致的资源消耗。
        $this->assertText('watermark_text', (string) ($params['watermark_text'] ?? ''));
        $this->assertText('custom_args', (string) ($params['custom_args'] ?? ''));

        // 剔除空值后返回，is：Builder 侧用 isset()/empty() 判断“是否该有 token”。
        return $this->filterBlankFields($params);
    }

    /**
     * 剔除 null / 空字符串 / 空数组字段。
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function filterBlankFields(array $params): array
    {
        // array_filter 默认回调保留“真值”；这里显式列空判定，
        // 避免 0 / "0"（合法 CRF）等真值为假的合法值被误删。
        return array_filter($params, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * 枚举白名单断言。
     *
     * $required=true 时空字符串触发“必填项”错误；否则返回成功并交由
     * filterBlankFields 剔除，保证“可选枚举未传”与“传入非法值”行为分离。
     *
     * @param string   $field   字段名（用于错误消息）
     * @param string   $value   待校验值
     * @param string[] $allowed 允许值集合
     *
     * @throws InvalidArgumentException 值不在集合内或必填却为空
     */
    private function assertEnum(string $field, string $value, array $allowed, bool $required = false): void
    {
        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException("参数 {$field} 为必填项");
            }

            return;
        }

        // in_array 使用严格模式 ($strict=true)，杜绝类型宽松导致的绕过。
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "参数 {$field} 的值 '{$value}' 不在允许范围内，可选值: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * 数值闭区间断言（用于 CRF 等）。
     *
     * @throws InvalidArgumentException 越界
     */
    private function assertRange(string $field, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("参数 {$field} 的值 {$value} 超出范围 [{$min}, {$max}]");
        }
    }

    /**
     * 时间戳格式断言（HH:MM:SS[.mmm]）。
     *
     * @throws InvalidArgumentException 格式不匹配
     */
    private function assertTime(string $field, string $value): void
    {
        // 空串跳过：可选时间戳由调用方决定是否存在。
        if ($value !== '' && !preg_match(self::TIME_PATTERN, $value)) {
            throw new InvalidArgumentException(
                "参数 {$field} 的时间格式无效，需要 HH:MM:SS 或 HH:MM:SS.mmm"
            );
        }
    }

    /**
     * 通用正则断言（码率 / 几何）。
     *
     * @throws InvalidArgumentException 格式不匹配
     */
    private function assertPattern(string $field, string $value, string $pattern): void
    {
        if ($value !== '' && !preg_match($pattern, $value)) {
            throw new InvalidArgumentException("参数 {$field} 的格式无效: '{$value}'");
        }
    }

    /**
     * 文本长度上限断言（水印文字 / 专家参数）。
     *
     * 依赖 polyfill-mbstring（已由 vendor/composer/autoload_files 加载），
     * 按字符计而非字节计，中英文混合场景不会误伤。
     *
     * @throws InvalidArgumentException 超长
     */
    private function assertText(string $field, string $value): void
    {
        if ($value !== '' && mb_strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException(
                "参数 {$field} 的文本长度超过限制 ({self::TEXT_MAX_LENGTH} 字符)"
            );
        }
    }
}