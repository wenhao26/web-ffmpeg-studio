<script setup lang="ts">
import { ref } from 'vue';
import { NFormItem, NInput, NSelect, NButton, useMessage } from 'naive-ui';
import { useParamsStore } from '@/stores/params';
import api from '@/api';

const store = useParamsStore();
const message = useMessage();

const imageInput = ref<HTMLInputElement | null>(null);
const uploadingImage = ref(false);
const imageName = ref('');

function pickImage(): void {
  imageInput.value?.click();
}

async function handleImageChange(e: Event): Promise<void> {
  const target = e.target as HTMLInputElement;
  const file = target.files?.[0];
  target.value = '';
  if (!file) return;

  uploadingImage.value = true;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const { data } = await api.post('/media/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    store.params.watermarkImage = data.file_id;
    imageName.value = data.filename;
    message.success('水印图片上传成功');
  } catch (err: any) {
    message.error(err.message ?? '水印图片上传失败');
  } finally {
    uploadingImage.value = false;
  }
}

function clearImage(): void {
  store.params.watermarkImage = '';
  imageName.value = '';
}
</script>

<template>
  <div class="space-y-3">
    <n-form-item label="水印类型">
      <n-select
        v-model:value="store.params.watermarkType"
        :options="[{ label: '无', value: 'none' }, { label: '文字', value: 'text' }, { label: '图片', value: 'image' }]"
        size="small"
      />
    </n-form-item>

    <template v-if="store.params.watermarkType === 'text'">
      <n-form-item label="水印文字">
        <n-input v-model:value="store.params.watermarkText" placeholder="输入水印文字（支持中文）" size="small" />
      </n-form-item>
      <n-form-item label="位置">
        <n-select v-model:value="store.params.watermarkPosition" :options="store.positionList.map(p => ({ label: p, value: p }))" size="small" />
      </n-form-item>
    </template>

    <template v-else-if="store.params.watermarkType === 'image'">
      <n-form-item label="水印图片">
        <input
          ref="imageInput"
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          class="hidden"
          @change="handleImageChange"
        />
        <div class="flex items-center gap-2 w-full min-w-0">
          <n-button size="small" :loading="uploadingImage" @click="pickImage">
            {{ store.params.watermarkImage ? '重新选择' : '选择图片' }}
          </n-button>
          <span v-if="imageName" class="text-xs text-gray-400 truncate flex-1 min-w-0">{{ imageName }}</span>
          <n-button v-if="store.params.watermarkImage" size="tiny" tertiary @click="clearImage">清除</n-button>
        </div>
      </n-form-item>
      <n-form-item label="位置">
        <n-select v-model:value="store.params.watermarkPosition" :options="store.positionList.map(p => ({ label: p, value: p }))" size="small" />
      </n-form-item>
      <p class="text-xs text-gray-400">图片水印以第二输入流叠加，支持 PNG / JPEG / GIF / WebP。</p>
    </template>
  </div>
</template>
