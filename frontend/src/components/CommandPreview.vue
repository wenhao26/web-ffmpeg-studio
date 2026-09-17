<script setup lang="ts">
import { computed } from 'vue';
import { NCard } from 'naive-ui';
import { useParamsStore } from '@/stores/params';

const store = useParamsStore();

const command = computed(() => store.commandPreview);

// 预先计算高亮片段：避免在模板里对含空格的 <pre> 内容做二次排版，
// 也顺带 trim 掉可能的前导空白。
const segments = computed(() => highlight(command.value.trim()));

// 简单的语法高亮：按空格分割 token，根据 token 类型着色
function highlight(cmd: string): { text: string; color: string }[] {
  if (!cmd) return [];
  const tokens = cmd.split(/(\s+)/);
  const result: { text: string; color: string }[] = [];

  for (const token of tokens) {
    if (/^\s+$/.test(token)) {
      result.push({ text: token, color: 'text-gray-400' });
    } else if (token === 'ffmpeg' || /^"-/.test(token) || token.startsWith('-') && !token.startsWith('--')) {
      // 标志和命令
      const color = token === 'ffmpeg' ? 'text-yellow-400 font-bold' : 'text-cyan-400';
      result.push({ text: token, color });
    } else if (token.startsWith('<') && token.endsWith('>')) {
      // 占位路径
      result.push({ text: token, color: 'text-green-500' });
    } else {
      // 参数值（文件名、数值等）
      result.push({ text: token, color: 'text-yellow-300' });
    }
  }
  return result;
}

function copyCommand(): void {
  navigator.clipboard?.writeText(command.value);
}
</script>

<template>
  <NCard title="实时指令预览" size="small" :bordered="false">
    <div class="relative">
      <!-- 复制按钮 -->
      <n-button
        size="tiny"
        tertiary
        style="position:absolute;top:8px;right:8px;z-index:1"
        @click="copyCommand"
      >
        📋 复制
      </n-button>

      <!-- 高亮命令：<pre> 内不得有换行/缩进，
           否则 whitespace-pre-wrap 会把模板缩进当作前导空格渲染出来 -->
      <pre
        class="bg-gray-900 text-gray-100 text-sm p-4 rounded-lg overflow-x-auto whitespace-pre-wrap font-mono leading-relaxed max-h-80 overflow-y-auto text-left"
      ><code v-for="(seg, i) in segments" :key="i" :class="seg.color">{{ seg.text }}</code></pre>
    </div>

    <p v-if="!command" class="text-xs text-gray-400 mt-2">
      填写参数后自动生成 FFmpeg 命令。
    </p>
  </NCard>
</template>
