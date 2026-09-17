# AI Vibe Coding Workflow & Iteration Guide: Web FFmpeg Studio

## 1. 核心工作流原则
本项目采用 **AI Vibe Coding（人机协同敏捷开发）** 模式。为保证企业级代码质量、防止 AI 盲目瞎写或偏离架构，必须严格遵守以下原则：
- **小步快跑，逐个确认**：严禁一次性让 AI 生成整个项目。必须按照模块分阶段推进，**每个模块写完后必须经过人工测试与代码审查确认无误，方可进入下一个模块**。
- **契约优先**：在编写具体代码前，必须严格参照 `PRD.md`（产品需求）、`PROJECT.md`（架构与目录）以及 `.cursor/rules/` 下的代码安全规范。

---

## 2. 开发迭代路线图 (Milestones & Steps)

接下来，我们将按以下 5 个阶段逐步推进代码编写：

### Phase 1: 后端核心基建、框架初始化与安全服务 (Backend Foundation & Framework Setup)
- **目标**：完成 Webman 后端框架初始化、目录结构规范化，并实现底层的核心基建与安全基础类库。
- **具体任务**：
  1. **Webman 框架初始化与目录挂载**：
     - 在项目的 `backend/` 目录已创建初始化高性能 Webman 框架，在当前环境验证它即可。
     - 确认基础目录结构契合项目规范（如 `app/service/`、`config/` 等）。
  2. **配置解析与二进制路径校验服务**：
     - 编写 `config/ffmpeg.php` 配置文件，收敛 FFmpeg/FFprobe 二进制路径、超时时间与存储目录映射。
     - 编写 `BinaryChecker.php`，负责严格校验 FFmpeg/FFprobe 二进制文件的存在性、有效性及宿主机可执行权限（`is_executable`）。
  3. **安全命令拼装服务 (`CommandBuilder`)**：
     - 实现对前端动态传入参数的白名单过滤（如视频/音频编码器、预设、容器格式）。
     - 强制对所有动态输入使用 `escapeshellarg()` 转义，严防命令注入。
  4. **进程沙箱执行器 (`ProcessRunner`)**：
     - 基于 Symfony Process 组件封装安全的进程执行器。
     - 强制设定硬性超时机制（Timeout）防止死锁，并完整捕获 FFmpeg 核心输出的标准错误流 (`Stderr`)。

### Phase 2: 后端 API 控制器与探测接口 (Backend API & Probe)
- **目标**：打通媒体资产探测与指令执行接口。
- **具体任务**：
  1. 实现文件上传及双重校验服务（MIME-type 与二进制头 Magic Number 校验）。
  2. 实现 `/api/media/probe` 接口（调用 FFprobe 解析音视频元数据并返回结构化 JSON）。
  3. 实现 `/api/ffmpeg/execute` 接口（接收表单参数、生成并执行指令、返回执行结果及产物路径）。

### Phase 3: 前端基础壳子与状态管理 (Frontend Core & Pinia)
- **目标**：搭建 Vue 3 前端工程骨架与状态流。
- **具体任务**：
  1. 配置 Axios 拦截器与后端响应契约对接。
  2. 创建 Pinia 状态管理（集中管理当前媒体文件元数据、表单动态参数、实时指令预览）。
  3. 搭建主控台基础布局（双栏或三栏响应式结构，支持亮/暗主题）。

### Phase 4: 前端核心表单与实时指令预览 (Frontend UI & Live Preview)
- **目标**：实现“所见即所得”的可视化表单组件。
- **具体任务**：
  1. 实现文件上传组件与探测结果展示卡片。
  2. 实现基础转码、几何裁剪、水印叠加等表单面板。
  3. 实现**实时指令高亮预览组件**（表单修改时毫秒级动态更新底层 FFmpeg 命令）。

### Phase 5: 执行终端、资产预览与清理机制 (Execution & Lifecycle)
- **目标**：闭环整个系统。
- **具体任务**：
  1. 实现执行按钮、Loading 动画及标准错误日志（Stderr）终端模拟器。
  2. 集成视频播放器与产物下载卡片。
  3. 实现服务端的临时文件生命周期清理逻辑。

---

## 3. 每次迭代的交互规范 (Prompt Template for AI)

在进行下一个阶段的开发时，向 AI 发起 Prompt 时建议遵循以下格式：

> **示例 Prompt**：
> "当前我们处于 `WORKFLOW.md` 的 **Phase 1：后端核心基建与安全服务**。
> 请严格遵守 `.cursor/rules/ffmpeg-backend.md` 中的安全规范（如严格类型声明、escapeshellarg 转义、超时控制），
> 帮我编写 `CommandBuilder.php` 和 `ProcessRunner.php`。请一次只写这两个文件，并等待我的审查。"
