<script setup lang="ts">
import { computed } from 'vue';
import {
  NConfigProvider,
  NGlobalStyle,
  NMessageProvider,
  NDialogProvider,
  darkTheme,
} from 'naive-ui';
import AppContent from '@/AppContent.vue';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();

// Naive UI 通过 NConfigProvider 的 theme 切换整套组件主题；
// darkTheme 为 null 时使用默认亮色主题。
const theme = computed(() => (ui.dark ? darkTheme : null));
</script>

<template>
  <n-config-provider :theme="theme">
    <n-global-style />
    <n-message-provider>
      <n-dialog-provider>
        <AppContent />
      </n-dialog-provider>
    </n-message-provider>
  </n-config-provider>
</template>
