# ADR-003: HLS segment_%04d 采用手工引号、绕过 escapeshellarg

- 状态：已接受
- 日期：2026-09

## 背景

HLS 输出需要 `-hls_segment_filename ".../segment_%04d.ts"`，
其中 `%04d` 是 FFmpeg 的分片序号占位符。

Windows 版 `escapeshellarg()` 会把 `%` 替换为空格，导致占位符被破坏为
`segment_ 04d.ts`，hls muxer 直接报
`Could not write header ... Invalid argument`。

## 决策

`CommandBuilder::buildHlsSection()` 中，分片文件名**手工**用双引号包裹并
**刻意不**调用 `escapeshellarg()`：

```
-hls_segment_filename "{$basePath}/segment_%04d.ts"
```

安全性由以下前提保证（缺一不可）：

1. `$basePath` 由服务端基于任务 UUID 生成，**非用户输入**；
2. cmd 仅展开 `%VAR%` 形态，`%04d` 不构成变量展开；
3. 回归测试锁死该行为：`backend/tests/unit/CommandBuilderTest.php`
   「hls emits manual-quoted segment pattern preserving %04d」。

## 后果

- 禁止任何"顺手修复"（给该参数补 `escapeshellarg()` 或改单引号）。
- 若日后 `output_base_path` 可能含用户可控片段，必须先重新评估本 ADR。
- 修改 HLS 输出相关的命令拼装时，该单测必须保持通过。
