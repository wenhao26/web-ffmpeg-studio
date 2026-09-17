import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export interface ProbeFormat {
  format_name: string;
  duration: number;
  bit_rate: number;
  size: number;
}

export interface VideoStream {
  index: number;
  type: 'video';
  codec: string;
  width: number;
  height: number;
  fps: number;
  color_space: string;
}

export interface AudioStream {
  index: number;
  type: 'audio';
  codec: string;
  channels: number;
  sample_rate: number;
}

export type StreamInfo = VideoStream | AudioStream;

export interface ProbeResult {
  format: ProbeFormat;
  streams: StreamInfo[];
}

export interface MediaState {
  fileId: string | null;
  filename: string;
  fileSize: number;
  mimeType: string;
  probeData: ProbeResult | null;
  loading: boolean;
}

export const useMediaStore = defineStore('media', () => {
  const fileId = ref<string | null>(null);
  const filename = ref('');
  const fileSize = ref(0);
  const mimeType = ref('');
  const probeData = ref<ProbeResult | null>(null);
  const loading = ref(false);

  const videoStream = computed(() => probeData.value?.streams.find((s): s is VideoStream => s.type === 'video') ?? null);
  const audioStream = computed(() => probeData.value?.streams.find((s): s is AudioStream => s.type === 'audio') ?? null);
  const duration = computed(() => probeData.value?.format.duration ?? 0);

  function reset(): void {
    fileId.value = null;
    filename.value = '';
    fileSize.value = 0;
    mimeType.value = '';
    probeData.value = null;
    loading.value = false;
  }

  return {
    fileId, filename, fileSize, mimeType, probeData, loading,
    videoStream, audioStream, duration,
    reset,
  };
});
