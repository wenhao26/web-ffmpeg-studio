<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();
const { dark: darkMode } = storeToRefs(ui);
</script>

<template>
  <n-layout class="block h-screen">
    <n-layout-header
      class="flex items-center justify-between border-b border-gray-200 dark:border-gray-800 px-6"
      style="height: 56px"
    >
      <div class="flex items-center gap-3">
        <span class="text-lg font-bold">🎬 Web FFmpeg Studio</span>
        <slot name="header"></slot>
      </div>
      <n-button size="small" tertiary @click="ui.toggle">
        {{ darkMode ? '☀️ 亮色' : '🌙 暗色' }}
      </n-button>
    </n-layout-header>

    <n-layout-content class="block p-6 overflow-auto">
      <slot></slot>
    </n-layout-content>

    <n-layout-footer
      class="block w-full text-center text-xs text-gray-400 py-2 border-t border-gray-200 dark:border-gray-800"
    >
      Web FFmpeg Studio © 2026
    </n-layout-footer>
  </n-layout>
</template>
