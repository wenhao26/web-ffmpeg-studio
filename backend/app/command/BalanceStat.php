<?php

namespace app\command;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Swoole\Coroutine\run;

#[AsCommand('balance:stat', 'balance stat')]
class BalanceStat extends Command
{
    protected function configure(): void
    {
        // e.g. php webman balance:stat -f /vagrant/www/temp/csv/divide_details.csv -w 8
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_OPTIONAL,
            'CSV文件路径',
            base_path() . '/csv/divide_details.csv'
        )->addOption(
            'worker',
            'w',
            InputOption::VALUE_OPTIONAL,
            '并行计算协程数',
            8
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filepath = $input->getOption('file');
        $workerNum = (int)$input->getOption('worker');

        if (!file_exists($filepath)) {
            $output->writeln("<error>错误：文件 $filepath 不存在</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>📊 开始分析分成明细 CSV 数据：$filepath</info>");
        $output->writeln("<info>⚡ Swoole 协程并行计算 Worker：$workerNum</info>\n");

        $startTime = microtime(true);

        // 最终汇总结果
        $grandTotalBalance = '0.00';
        $totalRecords = 0;
        $negativeCount = 0; // 负余额/异常数
        $maxBalance = '0.00';
        $minBalance = null;

        // 余额区间分布统计
        $ranges = [
            '0_to_100' => 0,
            '100_to_1000' => 0,
            '1000_to_10000' => 0,
            'above_10000' => 0,
        ];

        // 启动协程容器运行 Mapreduce 模式
        run(function () use (
            $filepath,
            $workerNum,
            &$grandTotalBalance,
            &$totalRecords,
            &$negativeCount,
            &$maxBalance,
            &$minBalance,
            &$ranges
        ) {
            $taskChannel = new Channel(2000); // 待处理数据行队列
            $resultChannel = new Channel(1000); // 各 worker 的 Partial Result

            // 生产者协程：逐行解析 CSV（跳过 Header），压入队列
            Coroutine::create(static function () use ($filepath, $taskChannel) {
                $handle = fopen($filepath, 'rb');
                fgetcsv($handle, 0, ',', '"', ''); // 跳过 Header

                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (isset($row[9])) {
                        $taskChannel->push((string)$row[9]);
                    }
                }
                fclose($handle);
                $taskChannel->close();
            });

            // 消费者 Workers（并行局部计算 Partial Map）
            for ($i = 0; $i < $workerNum; $i++) {
                Coroutine::create(static function () use ($taskChannel, $resultChannel) {
                    $partialTotal = '0.00';
                    $partialCount = 0;
                    $partialNegatives = 0;
                    $partialMax = '0.00';
                    $partialMin = null;
                    $partialRanges = [
                        '0_to_100' => 0,
                        '100_to_1000' => 0,
                        '1000_to_10000' => 0,
                        'above_10000' => 0,
                    ];

                    while (true) {
                        $balanceStr = $taskChannel->pop();
                        if ($balanceStr === false) {
                            break;
                        }

                        // 清洗余额格式，转换为规范数值字符串
                        $balance = trim($balanceStr);
                        if (!is_numeric($balance)) {
                            continue;
                        }

                        // 高精度加法计算局部总额
                        $partialTotal = bcadd($partialTotal, $balance, 2);
                        $partialCount++;

                        // 负数与极值校验
                        if (bccomp($balance, '0.00', 2) < 0) {
                            $partialNegatives++;
                        }
                        if (bccomp($balance, $partialMax, 2) > 0) {
                            $partialMax = $balance;
                        }
                        if ($partialMin === null || bccomp($balance, $partialMin, 2) < 0) {
                            $partialMin = $balance;
                        }

                        // 余额分布区间判断
                        $val = (float)$balance;
                        if ($val >= 0 && $val < 100) {
                            $partialRanges['0_to_100']++;
                        } elseif ($val >= 100 && $val < 1000) {
                            $partialRanges['100_to_1000']++;
                        } elseif ($val >= 1000 && $val < 10000) {
                            $partialRanges['1000_to_10000']++;
                        } elseif ($val >= 10000) {
                            $partialRanges['above_10000']++;
                        }
                    }

                    // 将 Partial Result 存入汇总 Channel
                    $resultChannel->push([
                        'total' => $partialTotal,
                        'count' => $partialCount,
                        'negatives' => $partialNegatives,
                        'max' => $partialMax,
                        'min' => $partialMin,
                        'ranges' => $partialRanges,
                    ]);
                });
            }

            // 汇总协程
            for ($i = 0; $i < $workerNum; $i++) {
                $res = $resultChannel->pop(); // 天然的阻塞机制
                $grandTotalBalance = bcadd($grandTotalBalance, $res['total'], 2);
                $totalRecords += $res['count'];
                $negativeCount += $res['negatives'];

                if (bccomp($res['max'], $maxBalance, 2) > 0) {
                    $maxBalance = $res['max'];
                }
                if ($minBalance === null || bccomp($res['min'], $minBalance, 2) < 0) {
                    $minBalance = $res['min'];
                }

                foreach ($res['ranges'] as $key => $count) {
                    $ranges[$key] += $count;
                }
            }
        });

        $costTime = round((microtime(true) - $startTime) * 1000, 2);

        // 计算平均余额
        $avgBalance = $totalRecords > 0
            ? bcdiv($grandTotalBalance, (string)$totalRecords, 2)
            : '0.00';

        // 渲染终端分析报表 Table
        $output->writeln("<info>✅ 统计分析完成 (⏳ 耗时: $costTime ms)</info>\n");

        $table = new Table($output);
        $table->setHeaders(['指标分析项 (Metric)', '统计结果 (Value)']);
        $table->addRows([
            ['总记录行数', number_format($totalRecords) . ' 行'],
            ['余额总计 (Total Balance)', '¥ ' . number_format((float)$grandTotalBalance, 2)],
            ['平均余额 (Average)', '¥ ' . number_format((float)$avgBalance, 2)],
            ['最大单笔余额 (Max)', '¥ ' . number_format((float)$maxBalance, 2)],
            ['最小单笔余额 (Min)', '¥ ' . number_format((float)($minBalance ?? 0), 2)],
            ['异常/负数余额记录数', $negativeCount > 0 ? "<error>{$negativeCount} 条</error>" : '0 条'],
        ]);
        $table->render();

        $output->writeln("\n<comment>📈 余额区间分布占比：</comment>");
        $distTable = new Table($output);
        $distTable->setHeaders(['余额区间 (Range)', '账户数', '占比 (%)']);
        foreach ($ranges as $rangeKey => $cnt) {
            $percent = $totalRecords > 0 ? round(($cnt / $totalRecords) * 100, 2) : 0;
            $distTable->addRow([$rangeKey, number_format($cnt), $percent . '%']);
        }
        $distTable->render();

        return Command::SUCCESS;
    }
}
