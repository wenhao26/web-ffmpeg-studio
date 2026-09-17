# Project Architecture & Technical Map (PROJECT.md): Web FFmpeg Studio

## 1. 技术栈选型 (Tech Stack)

### 后端 (Backend)
- **Language**: PHP 8.x (启用严格类型声明 `declare(strict_types=1);`)
- **Framework**: 现代 PHP 框架，必须使用 Webman 框架
- **Core Engine**: 本地或项目内置的静态编译 FFmpeg / FFprobe 二进制可执行文件

### 前端 (Frontend)
- **Framework**: Vue 3 (Composition API) + Vite
- **State Management**: Pinia
- **UI Component Library**: Element Plus 或 Naive UI
- **Styling**: Tailwind CSS / SCSS

### 多媒体处理核心
- **Binaries**: `bin/ffmpeg/ffmpeg` 与 `bin/ffmpeg/ffprobe`（支持跨平台部署）

---

## 2. 企业级项目目录结构 (Directory Tree)

项目采用前后端分离但统一纳管的工程化目录结构：

```text
web-ffmpeg-studio/
├── PRD.md                  # 产品需求文档
├── PROJECT.md              # 项目架构与技术蓝图（本文件）
├── STORAGE_POLICY.md       # 文件生命周期与临时产物清理规范
├── .cursor/                # AI 辅助编程配置与规则
│   └── rules/
│       ├── ffmpeg-backend.mdc
│       └── ffmpeg-frontend.mdc
├── bin/                    # FFmpeg 生产级二进制文件存放目录
│   └── ffmpeg/
│       ├── linux/          # Linux 64-bit 静态编译二进制
│       └── windows/        # Windows 64-bit 静态编译二进制
├── backend/                # PHP 8.x 后端核心源码
│
└── frontend/               # Vue 3 前端工程
