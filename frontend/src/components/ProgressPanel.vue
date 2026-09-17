<script setup lang="ts">
import { computed } from 'vue';
import { NCard, NProgress, NTag, NAlert } from 'naive-ui';
import { useTaskStore } from '@/stores/task';

const taskStore = useTaskStore();

const executing = computed(() => taskStore.status === 'processing');

const progressStatus = computed(() => {
  if (taskStore.status === 'failed') return 'error';
  if (taskStore.status === 'completed') return 'success';
  return 'default';
});

const logBoxVisible = computed(() => taskStore.status !== 'idle');
</script>

<template>
  <NCard title="任务进度" size="small">
    <template #header-extra>
      <n-tag v-if="taskStore.status === 'processing'" type="warning" size="small">执行中</n-tag>
      <n-tag v-else-if="taskStore.status === 'completed'" type="success" size="small">完成</n-tag>
      <n-tag v-else-if="taskStore.status === 'failed'" type="error" size="small">失败</n-tag>
      <n-tag v-else type="default" size="small">待执行</n-tag>
    </template>

    <n-progress
      type="line"
      :percentage="Math.min(100, Math.round(taskStore.progress))"
      :status="progressStatus"
      :height="16"
    />

    <div class="flex items-center justify-between mt-2 text-xs text-gray-400">
      <span>{{ taskStore.progress.toFixed(1) }}%</span>
      <span v-if="taskStore.speed">速度 {{ taskStore.speed }} · 剩余 {{ taskStore.eta || '-' }}</span>
    </div>

    <n-alert
      v-if="taskStore.status === 'failed'"
      type="error"
      class="mt-3"
      title="转码失败"
    >
      <pre class="whitespace-pre-wrap break-all text-xs font-mono m-0">{{ taskStore.error || '未知错误，请查看下方日志' }}</pre>
    </n-alert>

    <div
      v-if="logBoxVisible"
      class="mt-3 bg-gray-900 rounded p-3 text-xs font-mono text-gray-300 max-h-72 overflow-y-auto"
    >
      <div v-if="taskStore.logs.length === 0" class="text-gray-500">等待日志输出...</div>
      <div v-for="(line, i) in taskStore.logs" :key="i" class="whitespace-pre-wrap">{{ line }}</div>
      <span v-if="executing" class="animate-pulse">▊</span>
    </div>
  </NCard>
</template>
