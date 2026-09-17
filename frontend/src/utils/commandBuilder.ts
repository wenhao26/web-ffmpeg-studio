/**
 * 前端命令预览构建器。
 *
 * 与后端 CommandBuilder 镜像，
 * 依据 Pinia params store 中的表单参数实时拼装 FFmpeg 命令行，
 * 供 CommandPreview 组件毫秒级展示。
 */
export function buildCommandPreview(p: {
  inputPath: string;
  outputPath: string;
  outputFormat: string;
  videoCodec: string;
  crf: number;
  preset: string;
  videoBitrate: string;
  audioCodec: string;
  audioBitrate: string;
  startTime: string;
  endTime: string;
  crop: string;
  scale: string;
  watermarkType: string;
  watermarkText: string;
  watermarkPosition: string;
  watermarkImage: string;
  customArgs: string;
}): string {
  const {
    inputPath, outputPath, outputFormat, videoCodec, crf, preset,
    videoBitrate, audioCodec, audioBitrate, startTime, endTime,
    crop, scale, watermarkType, watermarkText, watermarkPosition,
    watermarkImage, customArgs,
  } = p;

  const parts: string[] = ['ffmpeg', '-y'];

  // 输入
  parts.push('-i', inputPath);

  // 时间区间（裁剪起点）
  if (startTime) parts.push('-ss', startTime);
  if (endTime) parts.push('-to', endTime);

  // 裁剪
  if (crop) parts.push('-crop', crop);

  // 缩放（使用 -vf scale）
  if (scale) parts.push('-vf', `scale=${scale}`);

  // 视频编码
  if (videoCodec) parts.push('-c:v', videoCodec);
  if (crf != null && crf >= 0 && crf <= 51) parts.push('-crf', String(crf));
  if (preset) parts.push('-preset', preset);
  if (videoBitrate) parts.push('-b:v', videoBitrate);

  // 音频编码
  if (audioCodec) parts.push('-c:a', audioCodec);
  if (audioBitrate) parts.push('-b:a', audioBitrate);

  // 水印
  if (watermarkType === 'text' && watermarkText) {
    parts.push('-vf', `drawtext=text='${watermarkText}':fontfile=/Windows/Fonts/arial.ttf:fontsize=24:fontcolor=white:@${watermarkPosition}`);
  } else if (watermarkType === 'image' && watermarkImage) {
    parts.push('-i', watermarkImage);
    parts.push('-filter_complex', `[0:v][1:v]overlay=${watermarkPosition}`);
  }

  // 自定义参数（空格分隔，已在后端校验）
  if (customArgs) {
    parts.push(...customArgs.split(/\s+/).filter(Boolean));
  }

  // 输出路径与格式
  parts.push('-f', outputFormat, outputPath);

  return parts.join(' ');
}
