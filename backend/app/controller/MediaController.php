<?php
declare(strict_types=1);

namespace app\controller;

use App\Exception\InvalidArgumentException;
use App\Exception\InvalidFileException;
use App\Service\BinaryChecker;
use App\Service\ProcessRunner;
use App\Service\ProbeService;
use App\Service\UploadService;
use App\Support\ApiResponse;
use support\Request;
use support\Response;

/**
 * ============================================================================
 * MediaController — 媒体资产：上传 / 探测 / 下载
 * ============================================================================
 *
 * 路由（config/route.php）:
 *   POST /api/media/upload     multipart 上传（字段名 file）
 *   POST /api/media/probe      JSON { file_id } 探测元数据
 *   GET  /storage/outputs/{taskId}/{filename}  产物流式下载
 *
 * 边界:
 *   - 控制器只做「参数提取 + 服务编排 + 响应包装」，不写业务规则；
 *   - 所有业务异常向上抛，由全局异常处理器统一转为契约 JSON。
 * ============================================================================
 */
class MediaController
{
    /**
     * 文件上传。
     *
     * @param Request $request
     *
     * @return Response 契约结构见 docs/项目需求.md 2.2.1
     */
    public function upload(Request $request): Response
    {
        $file = $request->file('file');

        // Webman 的 file() 在表单缺少字段时返回 null，在此统一拒绝。
        if ($file === null) {
            throw new InvalidFileException('请选择要上传的文件（表单字段名 file）');
        }

        $service = new UploadService(config('ffmpeg'));
        $data    = $service->upload($file);

        return ApiResponse::success($data, '上传成功');
    }

    /**
     * 媒体探测：解析 file_id -> 绝对路径 -> ffprobe 结构化元数据。
     *
     * @param Request $request
     *
     * @return Response 契约结构见 docs/项目需求.md 2.2.2
     */
    public function probe(Request $request): Response
    {
        $fileId = (string) $this->body($request, 'file_id', '');

        if ($fileId === '') {
            throw new InvalidArgumentException('参数 file_id 为必填项');
        }

        $config = config('ffmpeg');

        // 1) file_id -> 磁盘绝对路径（含 meta 存在性与文件复检）。
        $inputPath = (new UploadService($config))->resolveInputPath($fileId);

        // 2) 组装 ffprobe 探测会话（BinaryChecker 负责二进制可执行校验）。
        $runner = new ProcessRunner((int) $config['process']['timeout']);
        $probe  = new ProbeService((new BinaryChecker($config))->checkFfprobe(), $runner);

        // 3) 探测 + 归一化，异常交给全局处理器。
        $data = $probe->probe($inputPath);

        return ApiResponse::success($data);
    }

    /**
     * 产物下载 / 播放（Response::file 流式返回）。
     *
     * 安全约束:
     *   - taskId 必须是 UUID v4 形态（防目录穿越）；
     *   - filename 必须命中白名单（output.* 或 segment_*.ts）。
     *
     * @param string $taskId   任务 UUID（路由参数）
     * @param string $filename 输出文件名（路由参数）
     *
     * @return Response 文件流（浏览器可直接播放/下载）
     *
     * @throws InvalidArgumentException 参数不合规时 400
     * @throws InvalidFileException     文件不存在时 404 语义（400 由全局映射）
     */
    public function download(Request $request, string $taskId, string $filename): Response
    {
        // UUID v4 正则（兼容大/小写与连字符）。
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $taskId)) {
            throw new InvalidArgumentException('非法的任务标识');
        }

        // 产物文件名白名单：主产物 + HLS 播放清单 + 分片（%04d 四位数）。
        if (!preg_match('/^(output\.(mp4|webm|mkv|mov)|output\.m3u8|segment_\d{4}\.ts)$/', $filename)) {
            throw new InvalidArgumentException('非法的产物文件名');
        }

        $outputDir  = (string) config('ffmpeg.storage.outputs');
        $filepath   = $outputDir . '/' . $taskId . '/' . $filename;

        if (!is_file($filepath)) {
            throw new InvalidFileException('产物不存在或已被清理');
        }

        // Webman Response::file() 设置 Last-Modified/304 缓存头并输出文件流，
        // 支持断点续传（Range）以适配 HLS 分片顺序请求。
        return response()->file($filepath);
    }

    /**
     * 从请求提取 JSON 参数（兼容 application/json 与 form 两种编码）。
     *
     * Webman 的 post() 只解析 form/x-www-form-urlencoded；契约接口以
     * application/json 提交，因此此处优先解析 rawBody，失败时回退 post()。
     *
     * @param string $key     参数名
     * @param mixed  $default 缺省值
     *
     * @return mixed
     */
    private function body(Request $request, string $key, mixed $default = null): mixed
    {
        $raw = (string) $request->rawBody();
        if ($raw !== '') {
            // JSON 解析仅在 content-type 为 json 时进行，其余交由 post()。
            $contentType = (string) $request->header('content-type', '');
            if (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded[$key] ?? $default;
                }
            }
        }

        return $request->post((string) $key, $default);
    }
}