<script setup lang="ts">
import { ref } from 'vue';
import { NButton } from 'naive-ui';

const props = defineProps<{
  filename?: string;
  fileSize?: number;
  busy?: boolean;
  probing?: boolean;
}>();

const emit = defineEmits<{
  (e: 'file-selected', file: File): void;
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

function triggerUpload(): void {
  if (props.busy) return;
  inputRef.value?.click();
}

function handleChange(e: Event): void {
  const target = e.target as HTMLInputElement;
  if (!target.files || target.files.length === 0) return;
  emit('file-selected', target.files[0]);
  target.value = '';
}

function handleDrop(e: DragEvent): void {
  dragging.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) emit('file-selected', file);
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}
</script>

<template>
  <div>
    <input
      ref="inputRef"
      type="file"
      accept="video/*"
      class="hidden"
      @change="handleChange"
    />

    <div
      class="border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition-colors"
      :class="dragging
        ? 'border-blue-400 bg-gray-50 dark:bg-gray-800'
        : 'border-gray-300 dark:border-gray-700 hover:border-blue-300'"
      @click="triggerUpload"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="handleDrop"
    >
      <p class="text-2xl mb-1">📂</p>
      <p class="text-sm">
        {{ busy ? '处理中，请稍候...' : '点击选择或拖拽视频文件到此处' }}
      </p>
      <p class="text-xs text-gray-400 mt-1">支持 MP4 / WebM / MKV / MOV / AVI，最大 1GB</p>
    </div>

    <div
      v-if="props.filename"
      class="mt-3 flex items-center justify-between rounded bg-gray-50 dark:bg-gray-800 px-3 py-2"
    >
      <div class="min-w-0">
        <p class="text-sm truncate">🎬 {{ props.filename }}</p>
        <p class="text-xs text-gray-400">
          {{ formatBytes(props.fileSize ?? 0) }}
          <span v-if="props.probing"> · 正在探测媒体信息...</span>
        </p>
      </div>
      <n-button size="tiny" tertiary :disabled="props.busy" @click.stop="triggerUpload">
        重新选择
      </n-button>
    </div>
  </div>
</template>
