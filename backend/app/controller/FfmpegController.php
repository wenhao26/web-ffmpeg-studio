<?php
declare(strict_types=1);

namespace app\controller;

use App\Exception\ExecuteException;
use App\Exception\InvalidArgumentException;
use App\Service\BinaryChecker;
use App\Service\CommandBuilder;
use App\Service\InputSanitizer;
use App\Service\ProcessRunner;
use App\Service\TaskCache;
use App\Service\UploadService;
use App\Support\ApiResponse;
use App\Support\Uuid;
use support\Request;
use support\Response;

/**
 * ============================================================================
 * FfmpegController — FFmpeg 指令执行接口
 * ============================================================================
 *
 * 路由（config/route.php）:
 *   POST /api/ffmpeg/execute   JSON 转码参数 → 登记异步任务 → 立即返回 task_id
 *
 * 执行模型（任务队列）:
 *   execute 只做「校验 + 构建命令 + 登记任务（pending）」，随即返回；
 *   真正的 FFmpeg 执行由独立的 task-worker 进程（app/process/TaskWorker）
 *   领取并通过 app/service/TaskRunner 同步执行。
 *
 *   这样长耗时转码不会阻塞处理 HTTP/SSE 的 web worker
 *   （Windows 下 webman 仅有一个 web worker）。
 *   进度与日志通过 SSE 通道 GET /api/ffmpeg/task/{task_id}/stream 推送，
 *   REST 回退 GET /api/ffmpeg/task/{task_id}/status（docs/项目需求.md 2.2.4~2.2.6）。
 *
 * 安全链（贯穿）:
 *   file_id -> UploadService::resolveInputPath()（服务端路径）
 *   -> InputSanitizer::sanitize()          （枚举/格式白名单）
 *   -> CommandBuilder::build()             （escapeshellarg + 专家参数白名单）
 *   -> TaskRunner::run()                   （proc_open + Hard Timeout 沙箱）
 * ============================================================================
 */
class FfmpegController
{
    /**
     * 输出容器格式 → 产物文件扩展名映射（只有白名单内格式可达此处）。
     */
    private const OUTPUT_EXTENSIONS = [
        'mp4'  => 'mp4',
        'webm' => 'webm',
        'mkv'  => 'mkv',
        'mov'  => 'mov',
    ];

    /**
     * FFmpeg 指令执行入口（异步）。
     *
     * 校验并构建命令后，仅登记任务（status=pending）即返回 task_id；
     * 真正的 FFmpeg 执行由 task-worker 进程领取（见 TaskRunner），
     * 进度与日志通过 SSE 通道推送。
     *
     * @param Request $request
     *
     * @return Response 契约结构见 docs/项目需求.md 2.2.3
     */
    public function execute(Request $request): Response
    {
        $config = config('ffmpeg');
        $params = $this->jsonBody($request);

        $fileId = (string) ($params['file_id'] ?? '');
        if ($fileId === '') {
            throw new InvalidArgumentException('参数 file_id 为必填项');
        }

        $outputFormat = (string) ($params['output_format'] ?? '');
        if (!in_array($outputFormat, ['mp4', 'webm', 'mkv', 'mov', 'hls'], true)) {
            throw new InvalidArgumentException('参数 output_format 不在允许范围内');
        }

        // ---- 1. 解析服务端输入路径（file_id -> 磁盘绝对路径）----
        $uploadSvc = new UploadService($config);
        $inputPath = $uploadSvc->resolveInputPath($fileId);

        // ---- 2. 组装输出路径与任务标识 ----
        $taskId     = Uuid::v4();
        $outputDir  = (string) $config['storage']['outputs'] . '/' . $taskId;

        // 输出目录须在调用 FFmpeg 前创建：FFmpeg 不会自动建目录，
        // 直接写不存在的目录会立即失败（exit ≠ 0）。递归建目录保证
        // 任务隔离目录（storage/outputs/{task_id}/）存在。
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new ExecuteException('无法创建输出目录', [
                'output_dir' => $outputDir,
            ]);
        }

        if ($outputFormat === 'hls') {
            // HLS：输出目录即 output_base_path（切片 + 播放清单落在此处）。
            $params['output_base_path'] = $outputDir;
        } else {
            $ext    = self::OUTPUT_EXTENSIONS[$outputFormat];
            $params['output_path'] = $outputDir . '/output.' . $ext;
        }

        // 图片水印：watermark_image 参数为文件 file_id，解析为绝对路径再拼命令。
        if (($params['watermark_type'] ?? '') === 'image' && !empty($params['watermark_image'])) {
            $params['watermark_image'] = $uploadSvc->resolveInputPath((string) $params['watermark_image']);
        }

        // ---- 3. 构建命令（白名单 + 转义集中在 CommandBuilder）----
        $builder = new CommandBuilder(new InputSanitizer($config), (new BinaryChecker($config))->checkFfmpeg());
        $params['input_path'] = $inputPath;
        $command = $builder->build($params);

        // ---- 5. 登记异步任务 ----
        // 先探测一次获取视频时长（用于计算进度百分比）
        $probeDuration = 0.0;
        try {
            $probeSvc = new \App\Service\ProbeService(
                (new BinaryChecker($config))->checkFfprobe(),
                new ProcessRunner((int) $config['process']['timeout'])
            );
            $probeData = $probeSvc->probe($inputPath);
            $probeDuration = (float) $probeData['format']['duration'];
        } catch (\Throwable $e) {
            // 探测失败不影响执行，进度仅按时间估算
        }

        // 登记任务（pending），由独立的 task-worker 进程领取执行。
        // Web worker 立即返回，不阻塞后续 SSE / status / upload 请求。
        TaskCache::create($taskId, $fileId, [
            'command'        => $command,
            'output_dir'     => $outputDir,
            'output_format'  => $outputFormat,
            'probe_duration' => $probeDuration,
            'timeout'        => (int) $config['process']['timeout'],
        ]);

        return ApiResponse::success([
            'task_id' => $taskId,
            'status'  => 'pending',
        ], '任务已登记');
    }

    /**
     * 从 application/json 请求体解析参数数组（数组/对象均允许）。
     *
     * 与 MediaController 的 body() 不同处：execute 的契约明确为 JSON 体，
     * 因此直接消费 rawBody，兼容 Webman 不自动解析 JSON 的行为。
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException JSON 非法或非对象数组
     */
    private function jsonBody(Request $request): array
    {
        $raw = (string) $request->rawBody();
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('请求体必须是合法的 JSON 对象');
        }

        return $decoded;
    }
}