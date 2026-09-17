<script setup lang="ts">
import { NFormItem, NInput } from 'naive-ui';
import { useParamsStore } from '@/stores/params';

const store = useParamsStore();
</script>

<template>
  <n-form-item label="自定义参数">
    <n-input
      v-model:value="store.params.customArgs"
      type="textarea"
      placeholder="例如: -tune film -profile:v high"
      size="small"
      :rows="3"
    />
  </n-form-item>
  <p class="text-xs text-gray-400 mt-1">高级用户专用，参数需经后端白名单校验。</p>
</template>
