import { defineStore } from 'pinia';
import { ref } from 'vue';

export type TaskStatus = 'idle' | 'processing' | 'completed' | 'failed';

export interface TaskState {
  taskId: string | null;
  status: TaskStatus;
  progress: number;
  speed: string;
  eta: string;
  logs: string[];
  outputUrl: string | null;
  error: string;
}

export const useTaskStore = defineStore('task', () => {
  const taskId = ref<string | null>(null);
  const status = ref<TaskStatus>('idle');
  const progress = ref(0);
  const speed = ref('');
  const eta = ref('');
  const logs = ref<string[]>([]);
  const outputUrl = ref<string | null>(null);
  const error = ref('');

  function start(id: string): void {
    taskId.value = id;
    status.value = 'processing';
    progress.value = 0;
    speed.value = '';
    eta.value = '';
    logs.value = [];
    outputUrl.value = null;
    error.value = '';
  }

  function updateProgress(p: number, s: string, e: string): void {
    progress.value = p;
    speed.value = s;
    eta.value = e;
  }

  function appendLog(line: string): void {
    logs.value.push(line);
    if (logs.value.length > 200) logs.value = logs.value.slice(-200);
  }

  function complete(url: string | null): void {
    status.value = 'completed';
    progress.value = 100;
    outputUrl.value = url;
  }

  function fail(message = ''): void {
    status.value = 'failed';
    error.value = message;
  }

  function reset(): void {
    taskId.value = null;
    status.value = 'idle';
    progress.value = 0;
    speed.value = '';
    eta.value = '';
    logs.value = [];
    outputUrl.value = null;
    error.value = '';
  }

  return { taskId, status, progress, speed, eta, logs, outputUrl, error, start, updateProgress, appendLog, complete, fail, reset };
});
