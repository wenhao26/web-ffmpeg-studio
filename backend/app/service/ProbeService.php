<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\ProbeException;

/**
 * ============================================================================
 * ProbeService — 媒体元数据探测服务
 * ============================================================================
 *
 * 职责:
 *   调用 FFprobe（经 BinaryChecker 校验后的可执行文件）对输入文件
 *   做结构化探测，并把 ffprobe 的原始 JSON 归一化为 docs/项目需求.md
 *   2.2.2 中约定的精简契约结构（视频/音频分流、fps 计算为浮点数）。
 *
 * 调用链:
 *   Controller -> BinaryChecker::checkFfprobe()  -> 构造 ffprobe 命令行
 *             -> ProcessRunner::execute()         -> 沙箱执行（继承 300s 超时）
 *             -> json_decode 提取 format/streams  -> 归一化返回
 *
 * 失败语义:
 *   - ffprobe 退出码非 0（文件损坏/无法解析）：ProbeException + stderr 上下文
 *   - 输出 JSON 不合法 / 不含任何媒体流：ProbeException
 *   控制器把 probe_context 顺带返回，前端可展示给用户定位问题素材。
 * ============================================================================
 */
class ProbeService
{
    private string $ffprobePath;

    private ProcessRunner $runner;

    public function __construct(string $ffprobePath, ProcessRunner $runner)
    {
        $this->ffprobePath = $ffprobePath;
        $this->runner      = $runner;
    }

    /**
     * 探测媒体文件并返回结构化元数据。
     *
     * @param string $inputPath 待探测文件绝对路径
     *
     * @return array{
     *   format: array{ format_name: string, duration: float, bit_rate: int, size: int },
     *   streams: array<int, array<string, mixed>>
     * }
     *
     * @throws ProbeException 进程失败 / 解析失败 / 无媒体流
     */
    public function probe(string $inputPath): array
    {
        $command = $this->buildCommand($inputPath);
        $result  = $this->runner->execute($command);

        // 进程级失败：把 ffprobe 的 stderr 尾部带给前端辅助诊断。
        if ((int) $result['exit_code'] !== 0) {
            throw new ProbeException('媒体文件无法被解析（ffprobe 失败）', [
                'exit_code' => $result['exit_code'],
                'stderr'    => $this->tail($result['stderr'], 1000),
            ]);
        }

        // 解析 JSON：ffprobe -of json 输出形如 {"programs":[],"streams":[...],"format":{...}}。
        $raw = json_decode($result['stdout'], true);
        if (!is_array($raw)) {
            throw new ProbeException('ffprobe 输出无法解析', [
                'stdout' => $this->tail($result['stdout'], 1000),
            ]);
        }

        $format = $this->normalizeFormat($raw['format'] ?? null);
        $streams = $this->normalizeStreams($raw['streams'] ?? []);

        if ($streams === []) {
            throw new ProbeException('未检测到任何可用的视频/音频流', [
                'format_name' => $format['format_name'],
            ]);
        }

        return [
            'format'  => $format,
            'streams' => $streams,
        ];
    }

    /**
     * 构造 ffprobe 命令行（全量格式 + 全量流，JSON 输出）。
     * 注意：-v error 静默非错误日志，.-of json 输出纯净 JSON 便于解析。
     */
    private function buildCommand(string $inputPath): string
    {
        return escapeshellarg($this->ffprobePath)
             . ' -v error -print_format json -show_format -show_streams '
             . escapeshellarg($inputPath);
    }

    /**
     * 归一化容器信息。
     *
     * ffprobe 字段缺省时以 null → 0 兜底，契约要求数值型字段永远存在。
     *
     * @param array<string, mixed>|null $raw
     *
     * @return array{ format_name: string, duration: float, bit_rate: int, size: int }
     */
    private function normalizeFormat(?array $raw): array
    {
        return [
            'format_name' => (string) ($raw['format_name'] ?? ''),
            'duration'    => (float) ((float) ($raw['duration'] ?? 0.0)),
            'bit_rate'    => (int) ($raw['bit_rate'] ?? 0),
            'size'        => (int) ($raw['size'] ?? 0),
        ];
    }

    /**
     * 归一化流列表：video/audio 分流，其余（subtitle/data）仅保留 index/type/codec。
     *
     * @param array<int, array<string, mixed>> $rawStreams
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStreams(array $rawStreams): array
    {
        $streams = [];
        foreach ($rawStreams as $index => $raw) {
            $type = (string) ($raw['codec_type'] ?? 'unknown');
            $codec = (string) ($raw['codec_name'] ?? 'unknown');

            $base = [
                'index' => (int) ($raw['index'] ?? $index),
                'type'  => $type,
                'codec' => $codec,
            ];

            switch ($type) {
                case 'video':
                    $streams[] = $base + [
                        'width'       => (int) ($raw['width'] ?? 0),
                        'height'      => (int) ($raw['height'] ?? 0),
                        'fps'         => $this->parseFraction($raw['avg_frame_rate'] ?? null),
                        'color_space' => (string) ($raw['pix_fmt'] ?? ''),
                    ];
                    break;

                case 'audio':
                    $streams[] = $base + [
                        'channels'    => (int) ($raw['channels'] ?? 0),
                        'sample_rate' => (int) ($raw['sample_rate'] ?? 0),
                    ];
                    break;

                default:
                    // 字幕 / 附加数据流：保留基本标识，不进入视频音频分片统计。
                    $streams[] = $base;
                    break;
            }
        }

        return $streams;
    }

    /**
     * 解析 ffprobe 的 "30000/1001" 分数形式帧率；不可解析时返回 0.0。
     */
    private function parseFraction(mixed $fraction): float
    {
        if (!is_string($fraction) || $fraction === '0/0' || $fraction === '') {
            return 0.0;
        }

        $parts = explode('/', $fraction);
        $den = (float) ($parts[1] ?? 1.0);
        if ($den <= 0.0) {
            return 0.0;
        }

        return round((float) $parts[0] / $den, 3);
    }

    /**
     * 截取字符串尾部（用于日志/上下文回显，控制 payload 体积）。
     */
    private function tail(string $text, int $maxBytes): string
    {
        return strlen($text) > $maxBytes ? mb_substr($text, -$maxBytes) : $text;
    }
}