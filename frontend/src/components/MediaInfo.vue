<script setup lang="ts">
import { computed } from 'vue';
import { NCard, NSpace, NStatistic, NTag } from 'naive-ui';
import { useMediaStore } from '@/stores/media';

const mediaStore = useMediaStore();

const videoStream = computed(() => mediaStore.videoStream);
const audioStream = computed(() => mediaStore.audioStream);

const formatName = computed(() => mediaStore.probeData?.format.format_name ?? '-');
const duration = computed(() => mediaStore.duration);
const bitRate = computed(() => mediaStore.probeData?.format.bit_rate ?? 0);
const fileSize = computed(() => mediaStore.fileSize);

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}
</script>

<template>
  <NSpace vertical :size="12" class="w-full">
    <NCard v-if="mediaStore.probeData" title="媒体元数据" size="small">
      <template #header-extra>
        <n-tag type="success">{{ formatName }}</n-tag>
      </template>

      <div class="grid grid-cols-2 gap-3 text-sm">
        <n-statistic label="时长" :value="duration.toFixed(1)" suffix="s" />
        <n-statistic label="码率" :value="(bitRate / 1000).toFixed(0)" suffix="kbps" />
        <n-statistic label="文件大小" :value="formatBytes(fileSize)" />
        <n-statistic label="视频流" :value="videoStream ? `${videoStream.width}×${videoStream.height}` : '-'" />
        <n-statistic label="音频流" :value="audioStream ? `${audioStream.channels}ch / ${audioStream.sample_rate}Hz` : '-'" />
      </div>
    </NCard>

    <NCard v-else title="探测结果" size="small">
      <p class="text-sm text-gray-400">暂无媒体信息，请先上传并探测文件。</p>
    </NCard>

    <NCard v-if="videoStream" title="视频流" size="small" :bordered="false">
      <div class="text-sm space-y-1">
        <p>编码: <span class="font-mono">{{ videoStream.codec }}</span></p>
        <p>分辨率: <span class="font-mono">{{ videoStream.width }} × {{ videoStream.height }}</span></p>
        <p>帧率: <span class="font-mono">{{ videoStream.fps }} fps</span></p>
        <p>色彩空间: <span class="font-mono">{{ videoStream.color_space }}</span></p>
      </div>
    </NCard>

    <NCard v-if="audioStream" title="音频流" size="small" :bordered="false">
      <div class="text-sm space-y-1">
        <p>编码: <span class="font-mono">{{ audioStream.codec }}</span></p>
        <p>声道: <span class="font-mono">{{ audioStream.channels }}</span></p>
        <p>采样率: <span class="font-mono">{{ audioStream.sample_rate }} Hz</span></p>
      </div>
    </NCard>
  </NSpace>
</template>
