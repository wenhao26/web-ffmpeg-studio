---
description: Enterprise Vue 3 frontend coding rules, reactive live command preview, component modularization, and terminal logging for Web FFmpeg Studio.
globs: ["frontend/**/*.vue", "frontend/**/*.ts"]
alwaysApply: false
---

# Vue 3 Frontend Coding & UI/UX Rules

当在前端编写、修改或审查涉及 Web FFmpeg Studio 的 Vue 3 组件与交互逻辑时，AI 与开发人员**必须**严格遵守以下企业级前端开发规范：

## 1. 响应式实时指令预览 (Reactive Live Command Preview)
- **所见即所得**：前端必须建立单向或双向的数据绑定机制。当用户在表单中调整任意参数（如修改目标分辨率、拖动滑块、输入水印文字、裁剪时间等）时，下方的“原始 FFmpeg 命令行预览”区域必须实现毫秒级的响应式更新。
- **高亮与可读性**：预览组件必须采用代码高亮样式（如 Markdown Code Block 或专用的指令高亮组件），方便用户直观理解表单操作是如何映射到底层 CLI 参数的。

## 2. 组件模块化与单一职责 (Component Modularization)
前端代码保持解耦，按功能拆分组件（路径以 `frontend/src/` 为根，**新增组件请放入既有目录**）：
- **表单区 (`components/ParamForm/`)**：`BasicTranscode` / `GeometryCrop` / `Watermark` / `ExpertMode` 四个子组件（以实际文件为准）。
- **上传与信息区**：`components/FileUpload.vue`、`components/MediaInfo.vue`。
- **指令预览区**：`components/CommandPreview.vue`（实时渲染原始命令行）。
- **执行与进度区**：`components/ProgressPanel.vue`（提交按钮、Loading、SSE 进度与日志滚动）。
- **产物区**：`components/ResultCard.vue`（视频播放 + 下载）。
- **外壳**：`App.vue` → `AppContent.vue` → `layouts/MainLayout.vue`（三栏布局）。

## 3. 表单验证与防御性交互 (Form Validation & Safety)
- **前置逻辑校验**：在用户点击“开始执行”之前，前端必须对核心必填项（如必须先上传文件、时间区间必须满足 `to > ss`、码率格式必须为数字等）进行前端校验，避免无效请求打到后端。
- **防止重复提交**：当 FFmpeg 任务正在执行时，必须禁用提交按钮，并给出清晰的 Loading 动画与进度提示，防止用户并发重复触发高负载的转码任务。

## 4. 状态管理与接口契约 (State Management & API Contracts)
- **Pinia 集中状态管理**：使用 Pinia 统一管理当前选中的媒体文件元数据、当前表单参数集、实时生成的指令串以及历史执行记录。
- **严格对接后端响应契约**：处理后端返回的标准 JSON 结构（`code`, `message`, `data`），形状以根目录 `openapi.yaml` 为准。当 `code !== 200` 时，必须通过 Naive UI 的 `useMessage()` 全局提示友好展示错误原因，而不是直接抛出白屏异常。
- **UI 组件库固定为 Naive UI**（禁止 Element Plus）；样式一律 Tailwind（禁止 SCSS）。
