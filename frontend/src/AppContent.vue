<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue';
import { useMessage, NButton, NCard, NCollapse, NCollapseItem, NTag } from 'naive-ui';
import MainLayout from '@/layouts/MainLayout.vue';
import FileUpload from '@/components/FileUpload.vue';
import MediaInfo from '@/components/MediaInfo.vue';
import BasicTranscode from '@/components/ParamForm/BasicTranscode.vue';
import GeometryCrop from '@/components/ParamForm/GeometryCrop.vue';
import Watermark from '@/components/ParamForm/Watermark.vue';
import ExpertMode from '@/components/ParamForm/ExpertMode.vue';
import CommandPreview from '@/components/CommandPreview.vue';
import ProgressPanel from '@/components/ProgressPanel.vue';
import ResultCard from '@/components/ResultCard.vue';
import { useMediaStore } from '@/stores/media';
import { useParamsStore } from '@/stores/params';
import { useTaskStore } from '@/stores/task';
import type { ProbeResult } from '@/stores/media';
import api from '@/api';

const mediaStore = useMediaStore();
const paramsStore = useParamsStore();
const taskStore = useTaskStore();
const message = useMessage();

const uploading = ref(false);
const probing = ref(false);
const eventSource = ref<EventSource | null>(null);

const executing = computed(() => taskStore.status === 'processing');
const busy = computed(() => uploading.value || probing.value);
const canExecute = computed(() => !!mediaStore.fileId && !executing.value && !busy.value);

type TagType = 'default' | 'primary' | 'info' | 'success' | 'warning' | 'error';

const statusText = computed(() => {
  if (!mediaStore.fileId) return '未上传';
  if (executing.value) return '执行中';
  if (taskStore.status === 'completed') return '已完成';
  if (taskStore.status === 'failed') return '失败';
  return '可执行';
});

const statusType = computed<TagType>(() => {
  if (!mediaStore.fileId) return 'default';
  if (executing.value) return 'warning';
  if (taskStore.status === 'completed') return 'success';
  if (taskStore.status === 'failed') return 'error';
  return 'info';
});

/** 上传 -> 自动探测 -> 填充媒体元数据 */
async function handleFileSelected(file: File): Promise<void> {
  uploading.value = true;
  disconnectSSE();
  taskStore.reset();
  mediaStore.probeData = null;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const { data } = await api.post('/media/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    mediaStore.fileId = data.file_id;
    mediaStore.filename = data.filename;
    mediaStore.fileSize = data.file_size;
    mediaStore.mimeType = data.mime_type;
    message.success('上传成功，正在探测媒体信息...');
    await probeFile(data.file_id);
  } catch (err: any) {
    message.error(err.message ?? '上传失败');
  } finally {
    uploading.value = false;
  }
}

async function probeFile(fileId: string): Promise<void> {
  probing.value = true;
  try {
    const { data } = await api.post('/media/probe', { file_id: fileId });
    mediaStore.probeData = data as ProbeResult;
    message.success('媒体信息探测完成');
  } catch (err: any) {
    message.warning(err.message ?? '媒体探测失败');
  } finally {
    probing.value = false;
  }
}

/** 组装执行参数（空值不下发；watermark_type 为 none 时省略，避免枚举校验失败） */
function buildPayload(): Record<string, unknown> {
  const p = paramsStore.params;
  const payload: Record<string, unknown> = {
    file_id: mediaStore.fileId,
    output_format: p.outputFormat,
    video_codec: p.videoCodec,
    audio_codec: p.audioCodec,
    crf: p.crf,
    preset: p.preset,
  };
  if (p.videoBitrate) payload.video_bitrate = p.videoBitrate;
  if (p.audioBitrate) payload.audio_bitrate = p.audioBitrate;
  if (p.startTime) payload.start_time = p.startTime;
  if (p.endTime) payload.end_time = p.endTime;
  if (p.crop) payload.crop = p.crop;
  if (p.scale) payload.scale = p.scale;
  if (p.watermarkType === 'text' && p.watermarkText) {
    payload.watermark_type = 'text';
    payload.watermark_text = p.watermarkText;
    payload.watermark_position = p.watermarkPosition;
  } else if (p.watermarkType === 'image' && p.watermarkImage) {
    payload.watermark_type = 'image';
    payload.watermark_image = p.watermarkImage;
    payload.watermark_position = p.watermarkPosition;
  }
  if (p.customArgs) payload.custom_args = p.customArgs;
  return payload;
}

async function execute(): Promise<void> {
  if (!mediaStore.fileId) return;
  try {
    const { data } = await api.post('/ffmpeg/execute', buildPayload());
    taskStore.start(data.task_id);
    connectSSE(data.task_id);
  } catch (err: any) {
    message.error(err.message ?? '执行失败');
  }
}

/** 建立 SSE 连接，接收实时进度与日志 */
function connectSSE(taskId: string): void {
  disconnectSSE();
  const es = new EventSource(`/api/ffmpeg/task/${taskId}/stream`);
  eventSource.value = es;

  es.addEventListener('progress', (e) => {
    try {
      const payload = JSON.parse((e as MessageEvent).data);
      if (payload.type === 'progress') {
        taskStore.updateProgress(payload.progress, payload.speed, payload.eta);
      }
    } catch { /* ignore parse errors */ }
  });

  es.addEventListener('log', (e) => {
    try {
      const payload = JSON.parse((e as MessageEvent).data);
      if (payload.type === 'log' && payload.line) {
        taskStore.appendLog(payload.line);
      }
    } catch { /* ignore */ }
  });

  es.addEventListener('complete', (e) => {
    try {
      const payload = JSON.parse((e as MessageEvent).data);
      if (payload.type === 'complete') {
        taskStore.complete(payload.data.file_url ?? null);
        message.success('转码完成');
      }
    } catch { /* ignore */ }
    disconnectSSE();
  });

  es.addEventListener('error', (e) => {
    // 业务错误事件携带 data；连接层错误不带 data，由 onerror 处理
    if (!(e as MessageEvent).data) return;
    try {
      const payload = JSON.parse((e as MessageEvent).data);
      if (payload.type === 'error') {
        taskStore.fail(payload.message ?? '转码失败');
        message.error(payload.message ?? '转码失败');
      }
    } catch { /* ignore */ }
    disconnectSSE();
  });

  es.onerror = () => {
    // 浏览器 EventSource 内置重连；此处仅记录，不主动关闭
    console.warn('SSE 连接异常，浏览器将尝试重连...');
  };
}

function disconnectSSE(): void {
  eventSource.value?.close();
  eventSource.value = null;
}

onUnmounted(() => {
  disconnectSSE();
});
</script>

<template>
  <MainLayout>
    <template #header>
      <n-tag :type="statusType" size="small" :bordered="false">{{ statusText }}</n-tag>
    </template>

    <div class="mx-auto max-w-7xl grid grid-cols-1 xl:grid-cols-12 gap-5">
      <!-- 左栏 · 操作区 -->
      <div class="xl:col-span-7 space-y-5">
        <n-card title="上传视频" size="small">
          <FileUpload
            :filename="mediaStore.filename"
            :file-size="mediaStore.fileSize"
            :busy="uploading"
            :probing="probing"
            @file-selected="handleFileSelected"
          />
        </n-card>

        <n-card title="转码参数" size="small">
          <BasicTranscode />
          <n-collapse class="mt-2">
            <n-collapse-item title="高级 · 几何与裁剪" name="geometry">
              <GeometryCrop />
            </n-collapse-item>
            <n-collapse-item title="高级 · 水印叠加" name="watermark">
              <Watermark />
            </n-collapse-item>
            <n-collapse-item title="高级 · 专家参数" name="expert">
              <ExpertMode />
            </n-collapse-item>
          </n-collapse>
        </n-card>

        <CommandPreview />

        <n-button
          type="primary"
          size="large"
          block
          :loading="executing"
          :disabled="!canExecute"
          @click="execute"
        >
          {{ executing ? '转码执行中...' : '▶ 开始转码' }}
        </n-button>
      </div>

      <!-- 右栏 · 结果区 -->
      <div class="xl:col-span-5 space-y-5">
        <MediaInfo />
        <ProgressPanel v-if="mediaStore.fileId || taskStore.status !== 'idle'" />
        <ResultCard />
      </div>
    </div>
  </MainLayout>
</template>
