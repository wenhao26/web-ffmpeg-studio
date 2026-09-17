import axios from 'axios';
import type { AxiosResponse } from 'axios';

const api = axios.create({
  baseURL: '/api',
  timeout: 30000,
  headers: { 'Content-Type': 'application/json' },
});

// 请求拦截器：为所有 JSON 请求自动附加 Content-Type
api.interceptors.request.use(
  (config) => {
    if (config.headers) {
      // FormData（multipart）由浏览器自动设置 boundary，不要覆盖
      const ct = config.headers['Content-Type'];
      if (typeof ct !== 'string' || !ct.includes('multipart')) {
        config.headers['Content-Type'] = 'application/json';
      }
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// 响应拦截器：统一解析 `{code, message, data}` 契约结构
api.interceptors.response.use(
  (response) => {
    const { code, message, data } = response.data;
    // 200 以外的业务异常：弹出通知并 reject，便于调用方 catch
    if (code !== 200 && code !== undefined) {
      return Promise.reject(new Error(message ?? '请求失败'));
    }
    // 拦截器刻意把业务载荷提升到 `data`，调用方无需再解包 response.data.data
    return { code, message, data } as unknown as AxiosResponse;
  },
  (error) => {
    // 网络层错误：友好提示
    const msg = error.response?.data?.message ?? error.message ?? '网络异常，请稍后重试';
    return Promise.reject(new Error(msg));
  }
);

export default api;
