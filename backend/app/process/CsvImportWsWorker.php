<?php

namespace app\process;

use Swoole\Coroutine;
use Workerman\Connection\TcpConnection;

// WebSocket Worker 类与纯 Swoole 协程导入逻辑
class CsvImportWsWorker
{
    /**
     * 当收到前端客户端发送的消息时触发
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $payload = json_decode($data, true);
        if (!is_array($payload)) {
            return;
        }

        $action = $payload['action'] ?? '';

        if ($action === 'start_import') {
            $fileName = $payload['file_name'] ?? '';
            $filePath = "/vagrant/www/temp/csv/$fileName";

            if (!file_exists($filePath)) {
                $connection->send(json_encode([
                    'event' => 'error',
                    'message' => "文件不存在: {$filePath}"
                ], JSON_UNESCAPED_UNICODE));
                return;
            }

            // 🔥 启动纯 Swoole 协程进行异步处理
            Coroutine::create(function () use ($connection, $filePath) {
                $this->processCsvAndPushProgress($connection, $filePath);
            });
        }
    }

    /**
     * 流式读取 CSV，分批落库并推送进度
     */
    private function processCsvAndPushProgress(TcpConnection $connection, string $filePath): void
    {
        $startTime = microtime(true);

        try {
            // ==================== 步骤 1：单独句柄计算总行数 ====================
            $countHandle = fopen($filePath, 'rb');
            if (!$countHandle) {
                $connection->send(json_encode([
                    'event' => 'error',
                    'message' => '无法打开文件进行行数统计'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }

            $totalRows = 0;
            while (!feof($countHandle)) {
                $buffer = fread($countHandle, 65536); // 使用 64KB 缓冲区加速读取
                $totalRows += substr_count($buffer, "\n");
            }
            fclose($countHandle); // 🔥 必须立即关闭句柄，清除底层 EOF 标记！

            if ($totalRows <= 1) {
                $connection->send(json_encode([
                    'event' => 'error',
                    'message' => "CSV 文件为空或仅包含表头 (总行数: $totalRows)"
                ], JSON_UNESCAPED_UNICODE));
                return;
            }

            // 告知前端开始
            $connection->send(json_encode([
                'event' => 'started',
                'total_rows' => $totalRows - 1
            ], JSON_UNESCAPED_UNICODE));


            // ==================== 步骤 2：重新打开干净句柄，逐行读取 CSV ====================
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                $connection->send(json_encode([
                    'event' => 'error',
                    'message' => '无法打开文件进行数据解析'
                ], JSON_UNESCAPED_UNICODE));
                return;
            }

            // 跳过表头 (PHP 8.4 兼容写法)
            fgetcsv($handle, 0, ',', '"', "\\");

            $processed = 0;
            $batchData = [];
            $batchSize = 500;

            // 流式逐行读取
            while (($row = fgetcsv($handle, 0, ',', '"', "\\")) !== false) {
                // 过滤无用的空行
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                $batchData[] = [
                    'id' => $row[0] ?? ''
                ];

                $processed++;

                // 分批达到 500 条
                if (count($batchData) >= $batchSize) {
                    // 打印测试
                    // print_r($batchData);
                    echo "--- 已处理: {$processed} / " . ($totalRows - 1) . " ---" . PHP_EOL;

                    $this->insertToDbBatch($batchData);
                    $batchData = [];

                    $percent = round(($processed / ($totalRows - 1)) * 100, 2);
                    print_r('debug');

                    // 推送进度给前端
                    $connection->send(json_encode([
                        'event' => 'progress',
                        'processed' => $processed,
                        'total' => $totalRows - 1,
                        'percent' => $percent,
                        'speed' => round($processed / max((microtime(true) - $startTime), 0.001), 0)
                    ], JSON_UNESCAPED_UNICODE));

                    // 让出 CPU 协程切片，使 WebSocket 数据包能写进网卡发送出去
                    Coroutine::sleep(0.01);
                }
            }

            fclose($handle);

            // ==================== 步骤 3：处理尾部剩余数据 ====================
            if (!empty($batchData)) {
                $this->insertToDbBatch($batchData);
            }

            $costTime = round(microtime(true) - $startTime, 2);

            // 发送完成通知
            $connection->send(json_encode([
                'event' => 'completed',
                'processed' => $processed,
                'total' => $totalRows - 1,
                'percent' => 100,
                'cost_time' => $costTime,
                'message' => "🎉 成功导入 $processed 条数据，总耗时：$costTime 秒！"
            ], JSON_UNESCAPED_UNICODE));

        } catch (\Throwable $e) {
            $connection->send(json_encode([
                'event' => 'error',
                'message' => '运行异常: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 协程批量入库
     */
    private function insertToDbBatch(array $rows): void
    {
        // 模拟数据库落库，Swoole 协程挂起 10ms
        Coroutine::sleep(0.2);
    }
}
