export interface MediaUploadResponse {
  file_id: string;
  filename: string;
  file_size: number;
  mime_type: string;
  storage_path: string;
}

export interface ProbeResponse {
  format: {
    format_name: string;
    duration: number;
    bit_rate: number;
    size: number;
  };
  streams: Array<{
    index: number;
    type: string;
    codec: string;
    width?: number;
    height?: number;
    fps?: number;
    channels?: number;
    sample_rate?: number;
    color_space?: string;
  }>;
}

export interface ExecuteResponse {
  task_id: string;
  command: string;
  exit_code: number;
  duration: number;
  file_url: string;
  file_size: number;
}
