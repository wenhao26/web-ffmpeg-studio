import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

const STORAGE_KEY = 'wfs-theme';

export const useUiStore = defineStore('ui', () => {
  const dark = ref(localStorage.getItem(STORAGE_KEY) === 'dark');

  function apply(): void {
    document.documentElement.classList.toggle('dark', dark.value);
  }

  function toggle(): void {
    dark.value = !dark.value;
  }

  // immediate: 首次加载即把持久化的主题应用到 <html>，
  // 避免刷新后 Tailwind dark: 类（依赖 .dark 类）短暂失效。
  watch(
    dark,
    (value) => {
      localStorage.setItem(STORAGE_KEY, value ? 'dark' : 'light');
      apply();
    },
    { immediate: true }
  );

  return { dark, toggle, apply };
});
