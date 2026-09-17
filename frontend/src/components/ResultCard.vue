<script setup lang="ts">
import { computed } from 'vue';
import { NCard, NButton } from 'naive-ui';
import { useTaskStore } from '@/stores/task';

const taskStore = useTaskStore();

const outputUrl = computed(() => taskStore.outputUrl);
const fileName = computed(() => outputUrl.value?.split('/').pop() ?? 'output');
</script>

<template>
  <NCard title="转码产物" size="small">
    <template v-if="outputUrl">
      <video
        :src="outputUrl"
        controls
        class="w-full rounded-lg bg-black max-h-80"
        preload="metadata"
      />
      <div class="flex items-center justify-between mt-3 gap-3">
        <p class="text-xs font-mono text-gray-400 truncate">{{ outputUrl }}</p>
        <n-button tag="a" :href="outputUrl" :download="fileName" size="small" type="primary">
          ⬇ 下载
        </n-button>
      </div>
    </template>
    <p v-else class="text-sm text-gray-400">转码完成后将在此预览与下载。</p>
  </NCard>
</template>
