<script setup lang="ts">
import { NFormItem, NInput, NSelect, NInputNumber } from 'naive-ui';
import { useParamsStore } from '@/stores/params';

const store = useParamsStore();
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <n-form-item label="输出格式">
      <n-select v-model:value="store.params.outputFormat" :options="store.formatList.map(f => ({ label: f, value: f }))" size="small" />
    </n-form-item>
    <n-form-item label="视频编码">
      <n-select v-model:value="store.params.videoCodec" :options="store.videoCodecList.map(c => ({ label: c, value: c }))" size="small" />
    </n-form-item>
    <n-form-item label="音频编码">
      <n-select v-model:value="store.params.audioCodec" :options="store.audioCodecList.map(c => ({ label: c, value: c }))" size="small" />
    </n-form-item>
    <n-form-item label="CRF (0-51)">
      <n-input-number v-model:value="store.params.crf" :min="0" :max="51" size="small" style="width: 100%" />
    </n-form-item>
    <n-form-item label="预设">
      <n-select v-model:value="store.params.preset" :options="store.presetList.map(p => ({ label: p, value: p }))" size="small" />
    </n-form-item>
    <n-form-item label="视频码率">
      <n-input v-model:value="store.params.videoBitrate" placeholder="留空则按 CRF，如 2M" size="small" clearable />
    </n-form-item>
    <n-form-item label="音频码率">
      <n-input v-model:value="store.params.audioBitrate" placeholder="如 128k" size="small" clearable />
    </n-form-item>
  </div>
</template>
