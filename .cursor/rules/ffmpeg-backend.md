---
description: Enterprise PHP 8.x backend coding rules, command security, binary path resolution, and process execution for FFmpeg integration.
globs: ["backend/**/*.php"]
alwaysApply: false
---

# PHP FFmpeg Backend Coding & Security Rules

当在后端编写、修改或审查涉及 FFmpeg/FFprobe 二进制调用的 PHP 代码时，AI 与开发人员**必须**严格遵守以下生产级约束与安全红线：

## 1. 绝对安全与防命令注入 (Command Security)
- **禁止直接拼接**：严禁将任何未经强类型校验或转义的用户输入直接拼接到 shell 执行语句中。
- **强制使用参数转义**：所有动态传入 FFmpeg 的参数（如输出文件名、视频标题水印、时间切片坐标等）必须包裹在 `escapeshellarg()` 中进行严格转义。
- **白名单校验**：对于枚举型参数（如视频编码器 `libx264`/`libx265`、视频容器 `mp4`/`webm`、预设速度 `preset`），必须在后端进行严格的白名单校验，拒绝白名单之外的非法字符。

## 2. 二进制文件定位与状态校验 (Binary Path Resolution)
- **路径配置化**：FFmpeg 与 FFprobe 的二进制文件路径必须从系统配置文件或环境变量中读取，支持项目内嵌目录（如 `bin/ffmpeg/linux/ffmpeg`）或系统全局路径。
- **执行前校验**：在每次调用执行服务前，必须显式调用 `file_exists()` 与 `is_executable()` 检查目标二进制文件是否存在且具备可执行权限，否则必须抛出明确的异常阻断流程。

## 3. 进程安全控制与超时管理 (Process Sandbox & Timeout)
- **超时强管控**：所有 FFmpeg 进程调用必须设置硬性的超时时间（Timeout，例如最高 300 秒），防止死循环滤镜或超大视频死锁占用宿主机 CPU 资源。
- **进程输出捕获**：必须同时捕获标准输出（`stdout`）与标准错误输出（`stderr`），因为 FFmpeg 的转码进度和核心错误日志默认全部输出在 `stderr` 中。
- **安全执行组件推荐**：建议优先使用成熟的进程管理组件（如 Symfony Process 组件或安全的底层 `proc_open` 封装），严禁无防护地直接调用高危的 `exec()` 或 `shell_exec()`。

## 4. 统一的响应结构契约 (Structured Response)
后端所有 API 控制器必须返回标准信封（HTTP 状态与业务 `code` 同值）：
```json
{ "code": 200, "message": "success", "data": {} }
```
- `data` 的具体形状因接口而异（如 execute 返回 `task_id`/`status=pending`，status 返回进度与日志），**以根目录 `openapi.yaml` 为准**；改动响应结构必须同步该文件。
- 业务异常统一抛 `App\Exception\*`，由全局 `support/exception/Handler` 映射为契约 JSON（400/422/500/504），控制器不自行 try/catch。

### 5. PHP 编码风格与注释规范 (Coding Style & Comments)
- **现代特性与规范**：
  * 所有 PHP 文件头部必须严格声明 `declare(strict_types=1);`。
  * 遵循 PSR-12 编码规范，大括号换行、缩进及命名空间保持整洁规范。
  * 赋值不做等号对齐：`$a = 1;`（等号两侧各一个空格），禁止 `$a    = 1;` 之类补空格纵向对齐。
  * 命名采用规范的驼峰命名法：类名使用大驼峰（PascalCase），方法与变量使用小驼峰（camelCase）。
- **注释书写禁忌（严禁 AI 味）**：
  * 严禁使用机械化的步骤编号（如 `// 1. xxx`、`// 2. xxx`、`// 步骤一：xxx`）或无意义的流水账注释。
  * 严禁为显而易见的代码写冗余注释（如 `$count++; // 计数器加一`）。
- **高质量工程注释标准**：
  * 公共服务类（Service）与核心方法必须编写简洁专业的 PHPDoc，明确标注参数类型、返回值以及可能抛出的异常（`@throws`）。
  * 复杂的业务逻辑、算法分支或非直观的 FFmpeg 参数组合，应使用自然流畅的人类语言注释其设计意图（Why）与业务背景，而非复述代码在做什么（What）。
