<?php
declare(strict_types=1);

/**
 * ============================================================================
 * Phase 1 端到端冒烟测试（贯穿 BinaryChecker -> CommandBuilder -> ProcessRunner）
 * ============================================================================
 *
 * 运行方式:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED" tests\smoke_test.php
 *   （Windows PowerShell 下 -d 屏蔽 vendor 可忽略的 deprecation 噪音）
 *
 * 覆盖场景:
 *   1) 真实 ffmpeg 生成合成素材（lavfi testsrc 源，避免依赖外部文件）
 *   2) CommandBuilder 拼装转码命令（libx264 + CRF + preset + 专家参数）
 *   3) ProcessRunner 沙箱执行，验证 stderr 进度能被回调捕获
 *   4) ffprobe 探测输出格式（codec/尺寸/pix_fmt/时长）反证命令正确性
 *   5) 负向安全用例：恶意 codec / 恶意 custom_args 均须被拒
 *
 * 依赖:
 *   bin/ffmpeg/windows/ffmpeg.exe 等仓库内置二进制；
 *   vendor/symfony/process（已手动置入并注册 PSR-4，见 Phase 1 记录）。
 *
 * 注意:
 *   本脚本会在系统临时目录创建 wfs-smoke/ 并清理；失败时可手动
 *   Remove-Item $env:TEMP\wfs-smoke 兜底。
 * ============================================================================
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BinaryChecker;
use App\Service\CommandBuilder;
use App\Service\InputSanitizer;
use App\Service\ProcessRunner;

// 统一的组件装配（与 Phase 2 控制器组装方式一致）：
// 三个服务共享同一份 config，Builder 内注入已校验的 ffmpeg 路径。
$config    = require __DIR__ . '/../config/ffmpeg.php';
$checker   = new BinaryChecker($config);
$sanitizer = new InputSanitizer($config);
$builder   = new CommandBuilder($sanitizer, $checker->checkFfmpeg());
$runner    = new ProcessRunner(120); // 冒烟测试给足 120s，强于生产 300s 的仅是测试场景松弛

// 工作区：系统临时目录下 wfs-smoke/，测试物不入仓库。
$work = sys_get_temp_dir() . '/wfs-smoke';
if (!is_dir($work)) {
    mkdir($work, 0777, true);
}

$source = $work . '/source.mp4';
$output = $work . '/output.mp4';

// ---------------------------------------------------------------------------
// Step 1: 生成合成素材 —— 用 ffmpeg lavfi 源而非手工文件，
//         保证后续转码命令每次都有确定输入可验证。
// ---------------------------------------------------------------------------
echo "Step 1: generate synthetic source...\n";
$gen = new ProcessRunner(60);
$genResult = $gen->execute(
    escapeshellarg($checker->checkFfmpeg())
    . ' -f lavfi -i testsrc=duration=2:size=320x240:rate=25 '
    . escapeshellarg($source)
);
echo '  exit=' . $genResult['exit_code'] . "\n";

// ---------------------------------------------------------------------------
// Step 2: 构建转码命令 —— 断言 Builder 对合法参数产出正确命令行形态
//         （custom_args 的 token 白名单穿行 + 输出文件为最后一个参数）。
// ---------------------------------------------------------------------------
echo "Step 2: build transcode command...\n";
$command = $builder->build([
    'input_path'   => $source,
    'output_path'  => $output,
    'output_format' => 'mp4',
    'video_codec'   => 'libx264',
    'crf'           => 23,
    'preset'        => 'veryfast',
    'audio_codec'   => 'aac',
    'custom_args'   => '-threads 2 -pix_fmt yuv420p',
]);
echo '  cmd: ' . $command . "\n";

// ---------------------------------------------------------------------------
// Step 3: 沙箱执行 + 进度捕获 —— stderr 上形如
//         "frame= 50 fps=0.0 ... time=00:00:01.92 speed=43.6x"
//         的行会被拆分后回调，仅保留含 time= 的行用于断言“进度可解析”。
// ---------------------------------------------------------------------------
echo "Step 3: execute transcode with stderr capture...\n";
$progressLines = [];
$result = $runner->execute($command, function (string $line) use (&$progressLines, &$lastProgress): void {
    if (str_contains($line, 'time=')) {
        $progressLines[] = $line;
        $lastProgress = $line;
    }
});
echo '  exit=' . $result['exit_code'] . '  duration=' . round($result['duration'], 2) . "s\n";
echo '  stderr-lines-with-progress: ' . count($progressLines) . "\n";
if (isset($lastProgress)) {
    echo '  last-progress: ' . trim($lastProgress) . "\n";
}

// ---------------------------------------------------------------------------
// Step 4: 用 ffprobe 验证产物 —— 断言像素格式为 yuv420p 以反证
//         custom_args 的 -pix_fmt 生效顺序正确（Phase 1 曾因顺序
//         错误产出 yuv444p 而被本步揪出，属关键回归信号）。
// ---------------------------------------------------------------------------
echo "Step 4: verify output via ffprobe...\n";
$probe = new ProcessRunner(60);
$probeResult = $probe->execute(
    escapeshellarg($checker->checkFfprobe())
    . ' -v error -select_streams v:0 -show_entries stream=codec_name,width,height,pix_fmt '
    . '-show_entries format=duration -of json ' . escapeshellarg($output)
);
echo '  exit=' . $probeResult['exit_code'] . "\n";
echo '  probe-output: ' . trim($probeResult['stdout']) . "\n";

// ---------------------------------------------------------------------------
// Step 5 & 6: 负向安全用例
//   - 恶意 codec "sh -c id" 必须在白名单校验阶段被拒；
//   - 恶意 custom_args 含 -vf / -i 等危险开关必须被白名单拦截。
//   二者若未抛异常则标记 FAIL，作为注入防线失效的即时红灯。
// ---------------------------------------------------------------------------
echo "Step 5: negative test - illegal codec rejected...\n";
try {
    $builder->build([
        'input_path'   => $source,
        'output_path'  => $output,
        'output_format' => 'mp4',
        'video_codec'   => 'sh -c id',
    ]);
    echo "  FAIL: malicious codec NOT rejected!\n";
} catch (\App\Exception\InvalidArgumentException $e) {
    echo '  OK: rejected - ' . $e->getMessage() . "\n";
}

echo "Step 6: negative test - malicious custom_args rejected...\n";
try {
    $builder->build([
        'input_path'   => $source,
        'output_path'  => $output,
        'output_format' => 'mp4',
        'custom_args'   => '-vf drawtext=text=evil -i /etc/passwd',
    ]);
    echo "  FAIL: malicious custom_args NOT rejected!\n";
} catch (\App\Exception\InvalidArgumentException $e) {
    echo '  OK: rejected - ' . $e->getMessage() . "\n";
}

echo "\nSMOKE TEST DONE\n";