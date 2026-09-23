# Storage Policy & Lifecycle Management: Web FFmpeg Studio

## 1. 概述与设计目标

音视频处理属于**高磁盘占用型业务**。为防止临时文件堆积撑满磁盘、保障隐私
（用户素材不永久驻留），系统实行严格的**存储分区 + TTL 自动清理**策略。

核心事实（以代码为准）：

- 分区与 TTL 配置声明于 `backend/config/ffmpeg.php`（`storage` / `cleanup` 段）；
- 实际清理由 `backend/app/service/CleanupService.php` 执行（TTL 常量与其同值，
  修改保留时长须同步本文件与 `docs/项目需求.md` §2.5.2）；
- 触发方式：Workerman `Timer::add(3600, ...)` **每小时**执行一次
  （注册于 `backend/support/bootstrap.php`），同时调用 `TaskCache::gc()`
  回收过期任务 JSON。

---

## 2. 存储目录层级与隔离规范

### 2.1 三区（项目根 `storage/`，运行期自动创建，不入库）

```text
storage/
├── inputs/                  # 用户上传的原始媒体暂存区
│   ├── {file_id}.mp4|...    # 落盘文件名 = 随机 UUID（防路径穿越/枚举）
│   └── {file_id}.meta.json  # 上传元数据（原始名、MIME、大小、时间）
├── outputs/                 # 转码成品区（下载/播放/ HLS 切片）
│   └── {task_id}/           # 任务隔离子目录（task_id 为 UUID v4）
│       ├── output.mp4|webm|mkv|mov
│       ├── output.m3u8      # HLS 播放清单
│       └── segment_0001.ts  # HLS 分片（segment_%04d.ts）
└── temp/                    # 运行时临时工作区
    └── {task_id}/           # 分片中间文件、帧图片等
```

### 2.2 任务状态（不在 `storage/` 下）

任务 JSON 位于 `backend/storage/tasks/*.json`（含 `.claim.lock` 队列锁），
生命周期由 `TaskCache::gc()` 回收，详见 ADR-001 与 `docs/项目需求.md` §2.2.6。
**该目录不适用下表的三区 TTL。**

---

## 3. 生命周期（TTL）与清理

| 分区 | 保留时长 | TTL（秒） | 说明 |
|------|----------|-----------|------|
| `inputs/` | 24 小时 | 86400 | 素材只服务于当次任务链，加速回收 |
| `outputs/` | 72 小时 | 259200 | 给用户留足下载/分享缓冲期 |
| `temp/` | 1 小时 | 3600 | 工作区尽快回收 |
| `backend/storage/tasks/` | 任务 GC | — | `TaskCache::gc()`，随每小时清理一并执行 |

清理语义（`CleanupService`）：

- 文件：`filemtime` 早于阈值即 `unlink`；
- 空的任务子目录：清理后移除；
- 整个清理过程**失败静默**（try/catch），绝不影响在线服务。

---

## 4. 安全约束

- 上传文件名一律替换为 UUID，用户不可控任何服务端路径片段；
- 产物下载路由对 `taskId` 做 UUID v4 校验、对 `filename` 做白名单校验
  （`output.*` / `output.m3u8` / `segment_XXXX.ts`），防目录穿越；
- `storage/` 整目录在 `.gitignore` 中，任何运行期文件不得入库。

---

## 5. 变更守则

调整保留时长或分区结构时，必须同步：

1. `backend/app/service/CleanupService.php`（TTL 常量，实际生效处）
2. `backend/config/ffmpeg.php` `cleanup` / `storage` 段（声明处）
3. 本文件 §3 表格
4. `docs/项目需求.md` §2.5.2
