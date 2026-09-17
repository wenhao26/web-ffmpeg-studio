---
description: Enterprise Vue 3 frontend coding rules, reactive live command preview, component modularization, and terminal logging for Web FFmpeg Studio.
globs: ["frontend/**/*.vue", "src/**/*.vue"]
alwaysApply: false
---

# Vue 3 Frontend Coding & UI/UX Rules

当在前端编写、修改或审查涉及 Web FFmpeg Studio 的 Vue 3 组件与交互逻辑时，AI 与开发人员**必须**严格遵守以下企业级前端开发规范：

## 1. 响应式实时指令预览 (Reactive Live Command Preview)
- **所见即所得**：前端必须建立单向或双向的数据绑定机制。当用户在表单中调整任意参数（如修改目标分辨率、拖动滑块、输入水印文字、裁剪时间等）时，下方的“原始 FFmpeg 命令行预览”区域必须实现毫秒级的响应式更新。
- **高亮与可读性**：预览组件必须采用代码高亮样式（如 Markdown Code Block 或专用的指令高亮组件），方便用户直观理解表单操作是如何映射到底层 CLI 参数的。

## 2. 组件模块化与单一职责 (Component Modularization)
前端代码必须保持高度解耦，严格按功能模块拆分组件：
- **表单控制面板区 (`components/forms/`)**：针对不同的 FFmpeg 场景（如基础转码、几何裁剪、水印叠加、HLS切片）独立封装独立的表单子组件。
- **指令预览区 (`components/preview/`)**：负责动态展示生成的原始命令行。
- **执行与日志终端区 (`components/terminal/`)**：负责控制提交、展示加载状态、渲染后端返回的标准错误日志（Stderr）及进度信息。
- **资产预览区 (`components/player/`)**：集成视频播放器（Video.js 或原生 `<video>`）与产物下载卡片。

## 3. 表单验证与防御性交互 (Form Validation & Safety)
- **前置逻辑校验**：在用户点击“开始执行”之前，前端必须对核心必填项（如必须先上传文件、时间区间必须满足 `to > ss`、码率格式必须为数字等）进行前端校验，避免无效请求打到后端。
- **防止重复提交**：当 FFmpeg 任务正在执行时，必须禁用提交按钮，并给出清晰的 Loading 动画与进度提示，防止用户并发重复触发高负载的转码任务。

## 4. 状态管理与接口契约 (State Management & API Contracts)
- **Pinia 集中状态管理**：使用 Pinia 统一管理当前选中的媒体文件元数据、当前表单参数集、实时生成的指令串以及历史执行记录。
- **严格对接后端响应契约**：处理后端返回的标准 JSON 结构（`code`, `message`, `data`）。当 `code !== 200` 时，必须通过全局通知组件（如 Element Plus Message）友好展示错误原因，而不是直接抛出白屏异常。
