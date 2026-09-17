import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export interface ParamsState {
  outputFormat: string;
  videoCodec: string;
  audioCodec: string;
  crf: number;
  videoBitrate: string;
  audioBitrate: string;
  preset: string;
  startTime: string;
  endTime: string;
  crop: string;
  scale: string;
  watermarkType: string;
  watermarkText: string;
  watermarkPosition: string;
  watermarkImage: string;
  customArgs: string;
}

const DEFAULT_PARAMS: ParamsState = {
  outputFormat: 'mp4',
  videoCodec: 'libx264',
  audioCodec: 'aac',
  crf: 23,
  videoBitrate: '',
  audioBitrate: '',
  preset: 'veryfast',
  startTime: '',
  endTime: '',
  crop: '',
  scale: '',
  watermarkType: 'none',
  watermarkText: '',
  watermarkPosition: 'bottom-right',
  watermarkImage: '',
  customArgs: '',
};

export const useParamsStore = defineStore('params', () => {
  const params = ref<ParamsState>({ ...DEFAULT_PARAMS });

  const videoCodecList = ['libx264', 'libx265', 'libvpx-vp9', 'libaom-av1'];
  const audioCodecList = ['aac', 'libmp3lame', 'libopus'];
  const formatList = ['mp4', 'webm', 'mkv', 'mov', 'hls'];
  const presetList = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'];
  const positionList = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

  // drawtext 字体（与后端 CommandBuilder::resolveFontPath 一致）。
  // 盘符冒号需写成 \\: —— 后端实测单个反斜杠会被 filter 解析器吃掉。
  const FONT_FILE = 'C\\\\:/Windows/Fonts/msyh.ttc';

  const TEXT_COORDS: Record<string, [string, string]> = {
    'top-left': ['10', '10'],
    'top-right': ['w-text_w-10', '10'],
    'bottom-left': ['10', 'h-text_h-10'],
    center: ['(w-text_w)/2', '(h-text_h)/2'],
    'bottom-right': ['w-text_w-10', 'h-text_h-10'],
  };

  const OVERLAY_COORDS: Record<string, [string, string]> = {
    'top-left': ['10', '10'],
    'top-right': ['main_w-overlay_w-10', '10'],
    'bottom-left': ['10', 'main_h-overlay_h-10'],
    center: ['(main_w-overlay_w)/2', '(main_h-overlay_h)/2'],
    'bottom-right': ['main_w-overlay_w-10', 'main_h-overlay_h-10'],
  };

  /** filter 语境内转义用户文本（与后端一致） */
  function escapeFilterText(text: string): string {
    return text
      .replace(/\\/g, '\\\\')
      .replace(/:/g, '\\:')
      .replace(/,/g, '\\,')
      .replace(/'/g, "\\'");
  }

  function quote(value: string): string {
    return `"${value}"`;
  }

  /**
   * 生成与后端 CommandBuilder 完全一致（顺序/滤镜语法）的指令预览。
   * 路径一律以 <...-path> 占位符展示，参数部分逐字对齐后端真实命令。
   */
  function buildCommand(p: ParamsState): string {
    const imageWatermark = p.watermarkType === 'image' && !!p.watermarkImage;

    const parts: string[] = ['ffmpeg', '-y', '-i', '<input-path>'];
    // 图片水印第二输入紧随主输入声明（须早于输出选项，否则会被当成输入项）
    if (imageWatermark) parts.push('-i', '<watermark-image-path>');

    // 基础流选项（顺序同后端 buildBasicOptions）
    if (p.videoCodec) parts.push('-c:v', p.videoCodec);
    if (p.crf != null && p.crf >= 0 && p.crf <= 51) parts.push('-crf', String(p.crf));
    if (p.videoBitrate) parts.push('-b:v', p.videoBitrate);
    if (p.preset) parts.push('-preset', p.preset);
    if (p.audioCodec) parts.push('-c:a', p.audioCodec);
    if (p.audioBitrate) parts.push('-b:a', p.audioBitrate);
    if (p.startTime) parts.push('-ss', p.startTime);
    if (p.endTime) parts.push('-to', p.endTime);

    if (!imageWatermark) {
      // 无图水印：单一 -vf 链 crop,scale,drawtext
      const filters: string[] = [];
      if (p.crop) filters.push(`crop=${p.crop}`);
      if (p.scale) filters.push(`scale=${p.scale}`);
      if (p.watermarkType === 'text' && p.watermarkText) {
        const [x, y] = TEXT_COORDS[p.watermarkPosition] ?? TEXT_COORDS['bottom-right'];
        filters.push(
          `drawtext=text=${escapeFilterText(p.watermarkText)}:x=${x}:y=${y}` +
            `:fontsize=24:fontcolor=white@0.8:fontfile=${FONT_FILE}:expansion=none`
        );
      }
      if (filters.length > 0) parts.push('-vf', quote(filters.join(',')));
    } else {
      // 图片水印：filter_complex overlay（第二输入已在上面声明）
      const base = [p.crop ? `crop=${p.crop}` : '', p.scale ? `scale=${p.scale}` : '']
        .filter(Boolean)
        .join(',');
      const [x, y] = OVERLAY_COORDS[p.watermarkPosition] ?? OVERLAY_COORDS['bottom-right'];
      const graph = base
        ? `[0:v]${base}[base];[1:v][base]overlay=${x}:${y}[out]`
        : `[0:v][1:v]overlay=${x}:${y}[out]`;
      parts.push('-filter_complex', quote(graph));
      parts.push('-map', '[out]', '-map', '0:a?');
    }

    if (p.customArgs) parts.push(...p.customArgs.split(/\s+/).filter(Boolean));

    if (p.outputFormat === 'hls') {
      parts.push(
        '-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod',
        '-hls_segment_filename', quote('<output-dir>/segment_%04d.ts'),
        '<output-dir>/output.m3u8'
      );
    } else {
      parts.push('<output-path>');
    }

    return parts.join(' ');
  }

  const commandPreview = computed((): string => buildCommand(params.value));

  function setParam<K extends keyof ParamsState>(key: K, value: ParamsState[K]): void {
    params.value[key] = value;
  }

  function reset(): void {
    params.value = { ...DEFAULT_PARAMS };
  }

  return { params, videoCodecList, audioCodecList, formatList, presetList, positionList, commandPreview, setParam, reset };
});
