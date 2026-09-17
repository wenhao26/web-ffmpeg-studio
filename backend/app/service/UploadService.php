<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidFileException;
use App\Support\Uuid;
use Webman\Http\UploadFile;

/**
 * ============================================================================
 * UploadService — 媒体文件上传 + 双重校验 + 落盘服务
 * ============================================================================
 *
 * 安全模型（双重校验，见 docs/项目需求.md 2.2.1）:
 *   1. MIME 头校验：来自 multipart 请求的 Content-Type（getUploadMimeType()），
 *      仅作为『自称类型』参考——攻击者可伪造，因此不作为最终结论。
 *   2. Magic Number 校验：读取文件真实二进制头（前 16 字节）推断实际格式，
 *      这是判断『这个文件到底是什么』的唯一可信来源。
 *   3. 一致性核对：二者不一致 → 直接拒绝（伪造扩展名/伪造 MIME 的场景）。
 *
 * 命名与落盘:
 *   - 服务端生成 file_id（UUID v4），扩展名以 Magic Number 识别结果为准，
 *     杜绝用户提供 .php/.sh 等危险扩展名进入存储区；
 *   - 存储路径: storage/inputs/{file_id}.{ext}
 *   - 同时落一份 {file_id}.meta.json 元数据（原始名/mime/路径），
 *     供 probe/execute/download 阶段解析回真实绝对路径，并作为
 *     Phase 5 定时清理的扫描依据。
 *
 * 回调契约: 返回与 docs/项目需求.md 2.2.1 完全一致的结构。
 * ============================================================================
 */
class UploadService
{
    /**
     * 配置（storage.上传上限 / whitelist.mime_types / storage 分区）。
     */
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 执行上传全流程，返回契约化的文件信息。
     *
     * @param UploadFile $file Webman 已接收的 multipart 文件对象
     *
     * @return array{
     *   file_id: string,       UUID v4 唯一标识
     *   filename: string,      原始文件名（仅展示用）
     *   file_size: int,        字节大小
     *   mime_type: string,     Magic Number 识别出的真实 MIME
     *   storage_path: string,  相对项目根的落盘路径
     * }
     *
     * @throws InvalidFileException 任一校验环节失败
     */
    public function upload(UploadFile $file): array
    {
        $this->assertUploadState($file);
        $this->assertSize($file);
        $this->assertDeclaredMime($file);

        $magic = $this->detectMagic((string) $file->getPathname());
        $this->assertMagicMime($magic);
        $this->assertConsistency($file, $magic);

        // 注意：size/name 必须在 move() 之前读取！
        // Webman 的 move() 会把临时文件 rename 到目标盘，之后 SplFileInfo
        // 的原 pathname 已失效（stat failed），因此先快照尺寸与原始名。
        $fileSize = (int) $file->getSize();
        $rawName  = (string) ($file->getUploadName() ?? '');

        $fileId      = Uuid::v4();
        $extension   = $magic['ext'];
        $storedName  = $fileId . '.' . $extension;
        $inputDir    = (string) $this->config['storage']['inputs'];
        $destination = $inputDir . '/' . $storedName;

        // Webman::File::move() 内部兼容正/反斜杠并自动建目录。
        $file->move($destination);

        $storagePath = $this->toStoragePath($destination);

        $this->writeMetaFile($fileId, [
            'file_id'      => $fileId,
            'filename'     => $rawName,
            'file_size'    => $fileSize,
            'mime_type'    => $magic['mime'],
            'storage_path' => $storagePath,
            'created_at'   => time(),
        ]);

        return [
            'file_id'      => $fileId,
            'filename'     => $rawName,
            'file_size'    => $fileSize,
            'mime_type'    => $magic['mime'],
            'storage_path' => $storagePath,
        ];
    }

    /**
     * 依据 file_id 解析输入文件的磁盘绝对路径。
     *
     * 读取上传时写入的 {file_id}.meta.json；文件不存在或已过期清理
     * 视为无效资源（404 语义交由控制器映射）。
     *
     * @return string 输入文件绝对路径
     *
     * @throws InvalidFileException 元数据缺失 => 文件不存在/已被清理
     */
    public function resolveInputPath(string $fileId): string
    {
        $meta = $this->readMetaFile($fileId);
        if ($meta === null) {
            throw new InvalidFileException("媒体文件不存在或已过期: {$fileId}");
        }

        $inputDir = (string) $this->config['storage']['inputs'];
        // 仅取 meta 记录的存储文件名（服务端生成，无用户可控输入），
        // 再拼绝对路径，杜绝任何 file_id 变形伤及目录穿越。
        $fileName = basename((string) $meta['storage_path']);
        $abspath  = $inputDir . '/' . $fileName;

        // 落盘文件可能被清理任务删过（路径存在但文件没了），复检一次。
        if (!is_file($abspath)) {
            throw new InvalidFileException("媒体文件已被清理: {$fileId}");
        }

        return $abspath;
    }

    /**
     * 从绝对路径提取契约约定的相对路径（storage/inputs/...）。
     *
     * 以 '/storage/' 为锚点截取，而非依赖 __DIR__ 层级：
     *   - 三个存储分区（inputs/outputs/temp）都位于项目根 /storage 之下；
     *   - 契约 storage_path 保证恒以 "storage/" 开头（相对项目根）。
     * 这样无论服务类目录结构如何调整都不会算错前缀。
     */
    private function toStoragePath(string $absolute): string
    {
        $normalized = str_replace('\\', '/', $absolute);
        $position   = strpos($normalized, '/storage/');

        return $position === false ? $normalized : substr($normalized, $position + 1);
    }

    /**
     * 上传状态/大小/自称 MIME 三道前置检查。
     *
     * @throws InvalidFileException
     */
    private function assertUploadState(UploadFile $file): void
    {
        // UPLOAD_ERR_OK 之外（超限/中断/无文件）一律拒绝。
        if (!$file->isValid()) {
            throw new InvalidFileException('上传未完成或文件无效 (err=' . ($file->getUploadErrorCode() ?? 'unknown') . ')');
        }
    }

    /**
     * @throws InvalidFileException
     */
    private function assertSize(UploadFile $file): void
    {
        $max = (int) $this->config['upload']['max_size'];
        // getSize() 读取临时文件——此调用在 move() 之前，文件仍存在。
        $size = (int) $file->getSize();
        if ($size > $max) {
            throw new InvalidFileException(
                sprintf('文件大小 %d 字节超出上限 %d 字节', $size, $max)
            );
        }
    }

    /**
     * 前置 MIME 白名单（宽松信号，非最终结论）。
     *
     * @throws InvalidFileException MIME 完全不在白名单时早期拒绝
     */
    private function assertDeclaredMime(UploadFile $file): void
    {
        $declared = (string) ($file->getUploadMimeType() ?? '');
        if ($declared === '' || $declared === 'application/octet-stream') {
            // 浏览器/客户端未提供确切类型：留给 Magic Number 判定。
            return;
        }
        $this->assertInWhitelist($declared);
    }

    /**
     * Magic Number 识别出的真实 MIME 必须命中白名单（严格结论）。
     *
     * @param array{ mime: string, ext: string } $magic
     *
     * @throws InvalidFileException
     */
    private function assertMagicMime(array $magic): void
    {
        $this->assertInWhitelist($magic['mime']);
    }

    /**
     * 声称 MIME 与 Magic 识别结果一致性核对。
     *
     * @param array{ mime: string, ext: string } $magic
     *
     * @throws InvalidFileException 明显伪造（声称视频实为文本等）时拒绝
     */
    private function assertConsistency(UploadFile $file, array $magic): void
    {
        $declared = (string) ($file->getUploadMimeType() ?? '');
        if ($declared === '' || $declared === 'application/octet-stream') {
            return;
        }

        // 容忍小差异（如 audio/mpeg 与视频容器混排），但大类必须一致。
        if ($this->familyOf($declared) !== $this->familyOf($magic['mime'])) {
            throw new InvalidFileException(
                "文件类型与声明不一致: 声称 {$declared}，实际为 {$magic['mime']}"
            );
        }
    }

    /**
     * 将 MIME 归约为大类（video/audio/...），用于一致性粗判。
     */
    private function familyOf(string $mime): string
    {
        return strtok($mime, '/') ?: 'unknown';
    }

    /**
     * @throws InvalidFileException
     */
    private function assertInWhitelist(string $mime): void
    {
        $allowed = $this->config['whitelist']['mime_types'];
        if (!in_array($mime, $allowed, true)) {
            throw new InvalidFileException("不支持的文件类型: {$mime}");
        }
    }

    /**
     * 读取文件二进制头识别容器格式。
     *
     * 校验规则（覆盖 whitelist.mime_types 中的主流格式）:
     *   - MP4/MOV:   字节 4..8 为 'ftyp'（ISO-BMFF 家族）
     *   - WebM/MKV:  开头 0x1A45DFA3（EBML 头），后续 DocType 区分 webm/matroska
     *   - AVI:       'RIFF'+offset8 为 'AVI '
     *   - FLV:       'FLV'
     *   - MP3:       'ID3' 或 0xFFFx 同步字
     *   - OGG:       'OggS'
     *   - WAV:       'RIFF'+offset8 为 'WAVE'
     *   - FLAC:      'fLaC'
     *
     * @param string $path 待检文件绝对路径
     *
     * @return array{ mime: string, ext: string }
     *
     * @throws InvalidFileException 无法识别 / 不可读
     */
    public function detectMagic(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidFileException("无法读取上传文件: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidFileException("无法打开上传文件: {$path}");
        }

        $head = (string) fread($handle, 16);
        fclose($handle);

        // 逐规则探测（match 顺序：EBML 前置于 RIFF 无关；MP3 同步字最后兜底）。
        return match (true) {
            // ISO-BMFF 家族 (MP4/MOV)：offset 4 起为 'ftyp'。
            substr($head, 4, 4) === 'ftyp' => ['mime' => 'video/mp4', 'ext' => 'mp4'],

            // WebM / Matroska：EBML 魔数 0x1A45DFA3（byte 0..3）。
            substr($head, 0, 4) === "\x1A\x45\xDF\xA3" => ['mime' => 'video/webm', 'ext' => 'webm'],

            // AVI：'RIFF' + 'AVI '（offset 8..11）。
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'AVI ' => ['mime' => 'video/x-msvideo', 'ext' => 'avi'],

            // FLV 流头。
            substr($head, 0, 3) === 'FLV' => ['mime' => 'video/x-flv', 'ext' => 'flv'],

            // WAV：'RIFF' + 'WAVE'。
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE' => ['mime' => 'audio/wav', 'ext' => 'wav'],

            // OGG 容器（常见扩展名 .ogg/.oga/.ogv）。
            substr($head, 0, 4) === 'OggS' => ['mime' => 'audio/ogg', 'ext' => 'ogg'],

            // FLAC native stream。
            substr($head, 0, 4) === 'fLaC' => ['mime' => 'audio/x-flac', 'ext' => 'flac'],

            // PNG：固定 8 字节签名。
            substr($head, 0, 8) === "\x89PNG\r\n\x1A\n" => ['mime' => 'image/png', 'ext' => 'png'],

            // JPEG：SOI 标记 0xFFD8FF。
            substr($head, 0, 3) === "\xFF\xD8\xFF" => ['mime' => 'image/jpeg', 'ext' => 'jpg'],

            // GIF87a / GIF89a。
            substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a' => ['mime' => 'image/gif', 'ext' => 'gif'],

            // WebP：'RIFF' + 'WEBP'（offset 8..11）。
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => ['mime' => 'image/webp', 'ext' => 'webp'],

            // MP3：ID3 标签头 或 MPEG 音频同步字 0xFFFx（缺 ID3v2 的裸流）。
            substr($head, 0, 3) === 'ID3' || (ord($head[0] ?? "\x00") === 0xFF && (ord($head[1] ?? "\x00") & 0xE0) === 0xE0)
                => ['mime' => 'audio/mpeg', 'ext' => 'mp3'],

            // 兜底：不属于任何支持格式。
            default => throw new InvalidFileException('无法识别的媒体文件类型（Magic Number 不匹配）'),
        };
    }

    /**
     * 写元数据 JSON（{file_id}.meta.json 与文件放在同一目录）。
     *
     * @param array<string, mixed> $meta
     */
    private function writeMetaFile(string $fileId, array $meta): void
    {
        $target = $this->metaPath($fileId);
        file_put_contents($target, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * 读取并解析元数据，检查签名合法性。
     *
     * @return array<string, mixed>|null 文件不存在/解析失败返回 null
     */
    private function readMetaFile(string $fileId): ?array
    {
        $path = $this->metaPath($fileId);
        if (!is_file($path) && !is_readable($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * 元数据完整路径：storage/inputs/{file_id}.meta.json
     * （与媒体文件平铺同目录，前缀同为 file_id，便于 Phase 5 清理任务
     *  用 glob("*.meta.json") 快速枚举资源清单）。
     */
    private function metaPath(string $fileId): string
    {
        $inputDir = (string) $this->config['storage']['inputs'];

        return $inputDir . '/' . $fileId . '.meta.json';
    }
}