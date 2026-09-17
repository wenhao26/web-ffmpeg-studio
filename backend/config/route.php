<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 路由注册表
 * ============================================================================
 *
 * Phase 2 API 契约路由（docs/项目需求.md 2.2）:
 *   POST /api/media/upload                  文件上传（multipart, 字段名 file）
 *   POST /api/media/probe                   媒体元数据探测（JSON { file_id }）
 *   POST /api/ffmpeg/execute                FFmpeg 转码执行（JSON 参数）
 *   GET  /storage/outputs/{taskId}/{filename} 产物下载/播放
 *
 * 后续迭代（Phase 5 附件）:
 *   GET  /api/ffmpeg/task/{task_id}/stream  SSE 进度通道（规划中）
 *   GET  /api/ffmpeg/task/{task_id}/status  REST 轮询回退（规划中）
 *
 * 说明:
 *   - 控制器使用命名空间 app\controller（与框架模板保持一致）；
 *   - 路由级安全（参数白名单）在各控制器内部完成，此处只做挂载。
 * ============================================================================
 */

use Webman\Route;

Route::post('/api/media/upload', [app\controller\MediaController::class, 'upload']);
Route::post('/api/media/probe', [app\controller\MediaController::class, 'probe']);
Route::post('/api/ffmpeg/execute', [app\controller\FfmpegController::class, 'execute']);
Route::get('/storage/outputs/{taskId}/{filename}', [app\controller\MediaController::class, 'download']);

// 任务状态（SSE 进度流 + REST 状态查询）
Route::get('/api/ffmpeg/task/{taskId}/stream', [app\controller\TaskController::class, 'stream']);
Route::get('/api/ffmpeg/task/{taskId}/status', [app\controller\TaskController::class, 'status']);