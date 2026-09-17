<script setup lang="ts">
import { computed } from 'vue';
import { NFormItem, NInput, NTimePicker, useMessage } from 'naive-ui';
import { useParamsStore } from '@/stores/params';
import { useMediaStore } from '@/stores/media';

const store = useParamsStore();
const mediaStore = useMediaStore();
const message = useMessage();

// NTimePicker 的 value 是「时间戳」，为便于与后端 HH:MM:SS 字符串互转，
// 统一以当天 00:00:00 为基准（时区安全）。
const BASE = new Date();
BASE.setHours(0, 0, 0, 0);
const BASE_TS = BASE.getTime();

/** 媒体总时长（秒），未探测时为 0（不限制） */
const durationSecs = computed(() => Math.floor(mediaStore.duration || 0));

function pad(n: number): string {
  return String(n).padStart(2, '0');
}

/** HH:MM:SS[.mmm] -> 时间戳；非法返回 null */
function strToTs(s: string): number | null {
  if (!s) return null;
  const m = /^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:\.(\d+))?$/.exec(s.trim());
  if (!m) return null;
  const h = Number(m[1] ?? 0);
  const mi = Number(m[2]);
  const sec = Number(m[3]);
  return BASE_TS + (h * 3600 + mi * 60 + sec) * 1000;
}

/** 时间戳 -> HH:MM:SS */
function tsToStr(ts: number | null): string {
  if (ts == null) return '';
  const total = Math.max(0, Math.round((ts - BASE_TS) / 1000));
  return `${pad(Math.floor(total / 3600))}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

/** 限制在 [0, 媒体时长] 内 */
function clamp(ts: number | null): { value: number | null; clamped: boolean } {
  if (ts == null) return { value: null, clamped: false };
  if (ts < BASE_TS) return { value: BASE_TS, clamped: true };
  if (durationSecs.value > 0) {
    const max = BASE_TS + durationSecs.value * 1000;
    if (ts > max) return { value: max, clamped: true };
  }
  return { value: ts, clamped: false };
}

function assign(key: 'startTime' | 'endTime', ts: number | null): void {
  const { value, clamped } = clamp(ts);
  const str = tsToStr(value);
  // 00:00:00 视为「未设置」（后端据此省略 -ss/-to），picker 显示则由
  // getter 兜底到 BASE_TS —— 既保持空态语义，又避免 naive-ui 在空值时
  // 用“当前时刻”作为首次点击基准（会导致初次选择就收到越界警告）。
  store.params[key] = str === '00:00:00' ? '' : str;
  if (clamped) {
    message.warning(`已限制在媒体时长 ${tsToStr(BASE_TS + durationSecs.value * 1000)} 内`);
  }
}

const startValue = computed<number | null>({
  get: () => strToTs(store.params.startTime) ?? BASE_TS,
  set: (v) => assign('startTime', v),
});

const endValue = computed<number | null>({
  get: () => strToTs(store.params.endTime) ?? BASE_TS,
  set: (v) => assign('endTime', v),
});

// 超出媒体时长的时/分/秒在面板中置灰，从源头避免选到越界时间
function isHourDisabled(hour: number): boolean {
  if (durationSecs.value <= 0) return false;
  return hour > Math.floor(durationSecs.value / 3600);
}

function isMinuteDisabled(minute: number, hour: number | null): boolean {
  if (durationSecs.value <= 0 || hour == null) return false;
  const maxHour = Math.floor(durationSecs.value / 3600);
  return hour === maxHour && minute > Math.floor((durationSecs.value % 3600) / 60);
}

function isSecondDisabled(second: number, minute: number | null, hour: number | null): boolean {
  if (durationSecs.value <= 0 || hour == null || minute == null) return false;
  const maxHour = Math.floor(durationSecs.value / 3600);
  const maxMinute = Math.floor((durationSecs.value % 3600) / 60);
  return hour === maxHour && minute === maxMinute && second > (durationSecs.value % 60);
}
</script>

<template>
  <div class="space-y-3">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <n-form-item label="开始时间">
        <n-time-picker
          v-model:value="startValue"
          format="HH:mm:ss"
          size="small"
          clearable
          style="width: 100%"
          :is-hour-disabled="isHourDisabled"
          :is-minute-disabled="isMinuteDisabled"
          :is-second-disabled="isSecondDisabled"
        />
      </n-form-item>
      <n-form-item label="结束时间">
        <n-time-picker
          v-model:value="endValue"
          format="HH:mm:ss"
          size="small"
          clearable
          style="width: 100%"
          :is-hour-disabled="isHourDisabled"
          :is-minute-disabled="isMinuteDisabled"
          :is-second-disabled="isSecondDisabled"
        />
      </n-form-item>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <n-form-item label="裁剪 (W:H:X:Y)">
        <n-input v-model:value="store.params.crop" placeholder="1920:1080:0:0" size="small" clearable />
      </n-form-item>
      <n-form-item label="缩放 (W:H)">
        <n-input v-model:value="store.params.scale" placeholder="1280:720" size="small" clearable />
      </n-form-item>
    </div>
    <p class="text-xs text-gray-400">
      时间可选范围受媒体元数据时长限制
      <span v-if="durationSecs > 0">（总时长 {{ tsToStr(BASE_TS + durationSecs * 1000) }}）</span>
      <span v-else>（上传后自动探测）</span>；裁剪为 宽:高:X:Y，缩放为 宽:高。
    </p>
  </div>
</template>
