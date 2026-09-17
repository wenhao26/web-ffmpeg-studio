# Web FFmpeg Studio

> 基于 **Vue 3 + Webman(Workerman) + FFmpeg** 的在线音视频转码工作台：上传即转、参数可视化、进度实时可见、产物在线预览下载。

前后端分离，后端以独立进程池异步执行 FFmpeg，前端通过 **SSE** 接收实时转码进度与日志。

---

## 一、项目特点

### 1. 完整的转码能力
- **输出格式**：`mp4` / `webm` / `mkv` / `mov` / `hls`
- **视频编码**：`libx264` / `libx265` / `libvpx-vp9` / `libaom-av1`
- **音频编码**：`aac` / `libmp3lame` / `libopus`
- **画质控制**：CRF、preset 预设、视频/音频码率
- **高级几何**：时间裁剪（开始/结束时间）、画面裁剪（crop）、分辨率缩放（scale）
- **水印叠加**：文字水印 / 图片水印，支持五个锚点位置
- **专家模式**：自定义 FFmpeg 参数（经白名单校验后拼装）

### 2. 实时反馈的交互体验
- 上传后自动调用 **FFprobe** 探测元数据（时长、分辨率、编码、帧率、码率等）
- **实时指令预览**：所有参数改动即时生成可视化 FFmpeg 命令，一键复制
- **SSE 进度流**：实时推送百分比、编码速度、剩余时间与完整日志，并提供 REST 状态查询作为回退
- 转码产物**在线预览 + 下载**，暗色模式与响应式布局

### 3. 安全与工程化设计
- **参数白名单**：编码器、格式、preset、水印位置、MIME 等全部收敛到 `config/ffmpeg.php` 统一校验，杜绝参数注入
- **上传校验**：1 GiB 大小限制 + MIME 白名单，仅接受音视频与图片素材
- **进程隔离**：转码任务由独立的 `task-worker` 进程消费，长耗时任务**不阻塞** Web 请求与 SSE 通道
- **硬性超时**：FFmpeg 超时（默认 300s）强制终止进程树
- **生命周期清理**：输入 24h / 产物 72h / 临时区 1h TTL，自动回收磁盘

### 4. 技术栈
| 层 | 技术 |
| --- | --- |
| 后端 | PHP 8.1+ · Webman 2.x(Workerman) · symfony/process · Monolog |
| 前端 | Vue 3 (Composition API) · Vite 6 · TypeScript · Pinia · Naive UI · Tailwind CSS |
| 引擎 | 项目内置静态编译 FFmpeg / FFprobe（跨平台，随仓库分发） |

---

## 二、目录结构

```text
web-ffmpeg-studio/
├── backend/                     # PHP / Webman 后端
│   ├── app/
│   │   ├── controller/          # Media / Ffmpeg / Task 控制器
│   │   ├── service/             # 核心服务
│   │   │   ├── BinaryChecker.php    # FFmpeg 二进制可用性校验
│   │   │   ├── InputSanitizer.php   # 参数白名单校验
│   │   │   ├── CommandBuilder.php   # FFmpeg 命令拼装
│   │   │   ├── ProcessRunner.php    # 进程执行与超时控制
│   │   │   ├── UploadService.php    # 上传落盘与元数据
│   │   │   ├── ProbeService.php     # FFprobe 媒体探测
│   │   │   ├── TaskRunner.php       # 任务执行
│   │   │   ├── TaskCache.php        # 跨进程任务状态（文件持久化）
│   │   │   └── CleanupService.php   # 过期文件清理
│   │   └── process/             # Http / TaskWorker / Monitor 进程
│   └── config/ffmpeg.php        # 二进制 / 存储 / 上传 / 清理 / 白名单配置
├── frontend/                    # Vue 3 前端
│   └── src/
│       ├── components/          # FileUpload / MediaInfo / ProgressPanel / ResultCard ...
│       ├── stores/              # Pinia：media / params / task
│       └── api/                 # axios 实例与接口封装
├── bin/ffmpeg/                  # FFmpeg 二进制（windows / linux，不入库，见「FFmpeg 二进制获取」）
├── storage/                     # 运行期：inputs / outputs / temp（不提交）
├── docs/                        # 需求文档
├── PRD.md / PROJECT.md / STORAGE_POLICY.md / WORKFLOW.md
└── README.md
```

---

## 三、环境要求

| 组件 | 版本 | 说明 |
| --- | --- | --- |
| PHP | >= 8.1 | 需扩展：`bcmath`、`json`、`pcntl`(Linux)、`mbstring`、`fileinfo` |
| Composer | >= 2.x | 后端依赖管理 |
| Node.js | >= 18 | 前端构建（建议 20+） |

> FFmpeg / FFprobe **无需单独安装**：需将项目配套的静态编译二进制放入 `bin/ffmpeg/`（见下方「下载 FFmpeg 二进制」）。
> 若使用系统二进制，可设置环境变量 `FFMPEG_BIN` / `FFPROBE_BIN` 覆盖（见 `backend/config/ffmpeg.php`）。

---

## 四、FFmpeg 二进制获取（必做）

由于 FFmpeg 静态二进制单文件高达 150MB+（`bin/ffmpeg` 合计约 1GB），**不纳入 Git 仓库**，请手动下载后放入对应目录。

- **下载地址（百度网盘）**：<https://pan.baidu.com/s/1OLkR9guGGGLzLV1CKDLorA>
- **提取码**：`y9yj`

解压后，将 `windows/` 与 `linux/` 目录放入项目根目录的 `bin/ffmpeg/` 下，最终目录结构应为：

```text
bin/ffmpeg/
├── windows/
│   ├── ffmpeg.exe
│   ├── ffplay.exe
│   └── ffprobe.exe
└── linux/
    ├── ffmpeg
    ├── ffplay
    └── ffprobe
```

> 后端按操作系统自动选择：Windows 读取 `bin/ffmpeg/windows`，Linux 读取 `bin/ffmpeg/linux`（见 `backend/config/ffmpeg.php`）。

---

## 五、启动步骤

### 1. 克隆项目

```bash
git clone https://github.com/wenhao26/web-ffmpeg-studio.git
cd web-ffmpeg-studio
```

然后按上一节下载并放置 `bin/ffmpeg/` 二进制。

### 2. 启动后端（默认监听 `0.0.0.0:8787`）

```bash
cd backend
composer install

# Windows
php windows.php start

# Linux / macOS
php start.php start
```

启动后进程池包含：`web`(HTTP/SSE) + `task-worker`(FFmpeg 执行) + `monitor`。
停止 / 重启请使用 `php windows.php stop|restart`（Linux 为 `php start.php stop|restart`）。

### 3. 启动前端（开发模式，默认 `5173`）

```bash
cd frontend
npm install
npm run dev
```

浏览器访问 **http://localhost:5173**。
Vite 已配置代理，将 `/api` 与 `/storage` 转发到后端 `http://127.0.0.1:8787`，无需额外跨域配置。

### 4. 生产构建

```bash
cd frontend
npm run build      # 产物输出到 frontend/dist
```

将 `frontend/dist` 交给 Nginx 等静态服务器托管，并将 `/api`、`/storage` 反向代理至后端 `8787` 即可。

---

## 六、API 接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/media/upload` | 上传素材（multipart，字段名 `file`，上限 1GiB） |
| POST | `/api/media/probe` | FFprobe 探测媒体元数据（JSON `{ file_id }`） |
| POST | `/api/ffmpeg/execute` | 提交转码任务（JSON 参数） |
| GET | `/api/ffmpeg/task/{taskId}/stream` | SSE 实时进度 / 日志流 |
| GET | `/api/ffmpeg/task/{taskId}/status` | REST 轮询任务状态（回退方案） |
| GET | `/storage/outputs/{taskId}/{filename}` | 产物下载 / 在线播放 |

---

## 七、存储与清理策略

存储分区与生命周期详见 [`STORAGE_POLICY.md`](STORAGE_POLICY.md)：

```text
storage/
├── inputs/    # 用户上传的原始素材（TTL 24h）
├── outputs/   # 转码成品，供下载/播放（TTL 72h）
└── temp/      # 运行时临时工作区，如 HLS 切片（TTL 1h）
```

目录均为**运行期自动创建**，不纳入版本控制。

---

## 八、安全说明

- 所有动态参数经 `InputSanitizer` 白名单校验后才进入 `CommandBuilder`；
- 上传文件校验大小与 MIME，落盘文件名使用随机 UUID，避免路径穿越；
- FFmpeg 由 `symfony/process` / `proc_open` 以参数数组或转义方式执行，超时强制终止；
- 未知 / 越权路由统一返回 404，不泄露内部信息。

---

## License

本项目采用 [MIT License](backend/LICENSE)。
