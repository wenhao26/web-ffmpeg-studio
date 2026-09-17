<?php

namespace app\controller;

use GuzzleHttp\Client;
use support\Redis;
use support\Request;
use support\Response;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;

class DebugController
{
    public function redis(Request $request): Response
    {
        $key = 'test_key';
        Redis::set($key, rand());

        return response(Redis::get($key));
    }

    public function task(Request $request): Response
    {
        $pid = posix_getpid();
        echo 'Current PID: ' . $pid . PHP_EOL;

        // 模拟20个需要处理的任务
        $urls = [];
        for ($i = 1; $i <= 20; $i++) {
            $urls[] = "http://httpbin.org/delay/1?id=$i";
        }

        // 限制并发
        $chan = new Channel(20);
        $wg = new WaitGroup();

        $results = [];
        $startTime = microtime(true);

        $httpClient = new Client([
            'timeout' => 5.0,
            'verify' => false,
        ]);

        foreach ($urls as $url) {
            $wg->add();
            $chan->push(true); // 占用并发槽位，如果满了会在此挂起

            // 开启工作协程处理具体的请求
            Coroutine::create(static function () use ($httpClient, $url, $chan, $wg, &$results) {
                echo date('Y-m-d H:i:s') . " - Processing $url\n";

                try {
                    $response = $httpClient->get($url);
                    $results[] = [
                        'url' => $url,
                        'status' => $response->getStatusCode(),
                        'body' => json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'url' => $url,
                        'error' => $e->getMessage()
                    ];
                } finally {
                    $chan->pop(); // 释放槽位
                    $wg->done(); // 任务完成
                }
            });
        }

        // 等待所有协程执行完毕（真正的阻塞当前请求，不阻塞进程）
        $wg->wait();

        $totalTime = round(microtime(true) - $startTime, 2);

        return json([
            'pid' => $pid,
            'time_cost' => $totalTime . 's', // 20个请求/5并发/每个1s，预计耗时 4s 左右
            'count' => count($results),
            'data' => $results
        ]);
    }

}
