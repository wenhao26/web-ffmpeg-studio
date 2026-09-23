<?php
declare(strict_types=1);

function run_command_builder_tests(): void
{
    $binary = '/opt/ffmpeg/ffmpeg';

    $makeBuilder = static fn (): App\Service\CommandBuilder => new App\Service\CommandBuilder(
        new App\Service\InputSanitizer(wfs_config()),
        $binary
    );

    $base = [
        'output_format' => 'mp4',
        'input_path'    => '/data/in.mp4',
        'output_path'   => '/data/out.mp4',
        'video_codec'   => 'libx264',
        'crf'           => 23,
        'preset'        => 'fast',
        'audio_codec'   => 'aac',
    ];

    wfs_case('builder: basic mp4 chain is exact and ordered', function () use ($makeBuilder, $base, $binary) {
        $cmd = $makeBuilder()->build($base);
        $expected = escapeshellarg($binary) . ' -y'
            . ' -i ' . escapeshellarg('/data/in.mp4')
            . ' -c:v ' . escapeshellarg('libx264')
            . ' -crf 23'
            . ' -preset ' . escapeshellarg('fast')
            . ' -c:a ' . escapeshellarg('aac')
            . ' ' . escapeshellarg('/data/out.mp4');
        expect_true($cmd === $expected, "command mismatch\n  got: {$cmd}\n  want: {$expected}");
    });

    wfs_case('builder: -y precedes -i and -c:v precedes output', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base);
        expect_order($cmd, ' -y', ' -i ', '-y must come before first input');
        expect_order($cmd, ' -i ', ' -c:v ', '-i must come before codec options');
        expect_order($cmd, ' -c:a ', ' ' . escapeshellarg('/data/out.mp4'), 'codec options must precede output');
        expect_not_contains($cmd, ' -vf ', 'basic chain must not use -vf');
        expect_not_contains($cmd, ' -filter_complex ', 'basic chain must not use filter_complex');
    });

    wfs_case('builder: hostile input_path stays a single escaped token', function () use ($makeBuilder) {
        $evil = '/data/in"; touch /tmp/pwn; echo ".mp4';
        $cmd = $makeBuilder()->build([
            'output_format' => 'mp4',
            'input_path'    => $evil,
            'output_path'   => '/data/out.mp4',
        ]);
        expect_contains($cmd, ' -i ' . escapeshellarg($evil), 'hostile input_path must be escapeshellarg-wrapped');
        expect_contains($cmd, ' ' . escapeshellarg('/data/out.mp4'), 'output path must be escaped');
    });

    wfs_case('builder: time cut uses -ss/-to after -i', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + ['start_time' => '00:00:05', 'end_time' => '00:00:20']);
        expect_contains($cmd, ' -ss ' . escapeshellarg('00:00:05'), '-ss value must be escaped');
        expect_contains($cmd, ' -to ' . escapeshellarg('00:00:20'), '-to value must be escaped');
        expect_order($cmd, ' -i ', ' -ss ', '-ss must follow -i (precise seek)');
        expect_order($cmd, ' -ss ', ' -to ', '-ss must precede -to');
        expect_order($cmd, ' -to ', ' ' . escapeshellarg('/data/out.mp4'), '-to must precede output');
    });

    wfs_case('builder: crop+scale render as one -vf chain', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + ['crop' => '1280:720:0:0', 'scale' => '640:360']);
        expect_contains(
            $cmd,
            ' -vf ' . escapeshellarg('crop=1280:720:0:0,scale=640:360'),
            'crop and scale must form a single escaped -vf chain in order'
        );
        expect_not_contains($cmd, ' -filter_complex ', 'no filter_complex without image watermark');
    });

    wfs_case('builder: text watermark drawtext with escaped colon and corner coords', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + [
            'watermark_type'     => 'text',
            'watermark_text'     => '机密:2026',
            'watermark_position' => 'bottom-right',
        ]);
        expect_contains($cmd, 'drawtext=text=', 'drawtext filter must be present');
        expect_contains($cmd, '机密\:2026', 'colon inside watermark text must be filter-escaped');
        expect_contains($cmd, ':x=w-text_w-10:y=h-text_h-10', 'bottom-right text coords must be used');
        expect_contains($cmd, ':fontsize=24', 'drawtext fontsize must be fixed at 24');
        expect_contains($cmd, ':fontfile=', 'drawtext fontfile must be present');
        expect_contains($cmd, ':expansion=none', 'drawtext expansion must be disabled (literal % handling)');
        expect_not_contains($cmd, ' -filter_complex ', 'text watermark must not use filter_complex');
    });

    wfs_case('builder: text watermark center uses runtime expressions', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + [
            'watermark_type'     => 'text',
            'watermark_text'     => 'Demo',
            'watermark_position' => 'center',
        ]);
        expect_contains($cmd, ':x=(w-text_w)/2:y=(h-text_h)/2', 'center text coords must use expressions');
    });

    wfs_case('builder: image watermark second -i precedes -c:v', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + [
            'watermark_type'     => 'image',
            'watermark_image'    => '/data/wm.png',
            'watermark_position' => 'bottom-right',
        ]);
        expect_order($cmd, ' -i ' . escapeshellarg('/data/wm.png'), ' -c:v ', 'watermark input must precede codec options');
        expect_order($cmd, ' -i ' . escapeshellarg('/data/in.mp4'), ' -i ' . escapeshellarg('/data/wm.png'), 'main input must precede watermark input');
    });

    wfs_case('builder: image watermark without base chain uses direct overlay graph', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + [
            'watermark_type'     => 'image',
            'watermark_image'    => '/data/wm.png',
            'watermark_position' => 'bottom-right',
        ]);
        $graph = '[0:v][1:v]overlay=main_w-overlay_w-10:main_h-overlay_h-10[out]';
        expect_contains($cmd, ' -filter_complex ' . escapeshellarg($graph), 'direct overlay graph must match exactly');
        expect_contains($cmd, ' -map [out] -map 0:a?', 'output must map [out] and optional audio');
        expect_not_contains($cmd, '[base]', 'no [base] label without base filter chain');
        expect_not_contains($cmd, ' -vf ', '-vf must be absent when filter_complex is used');
    });

    wfs_case('builder: image watermark with crop/scale links through [base]', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + [
            'watermark_type'     => 'image',
            'watermark_image'    => '/data/wm.png',
            'watermark_position' => 'top-left',
            'crop'               => '1280:720:0:0',
            'scale'              => '640:360',
        ]);
        expect_contains(
            $cmd,
            '[0:v]crop=1280:720:0:0,scale=640:360[base];[1:v][base]overlay=10:10[out]',
            'base chain must be defined and referenced exactly once'
        );
    });

    wfs_case('builder: hls emits manual-quoted segment pattern preserving %04d', function () use ($makeBuilder) {
        $cmd = $makeBuilder()->build([
            'output_format'      => 'hls',
            'input_path'         => '/data/in.mp4',
            'video_codec'        => 'libx264',
            'output_base_path'   => '/data/out/task-1',
        ]);
        expect_contains($cmd, ' -f hls -hls_time 6 -hls_playlist_type vod', 'hls muxer options must be present');
        expect_contains(
            $cmd,
            '-hls_segment_filename "/data/out/task-1/segment_%04d.ts"',
            'segment pattern must keep manual double quotes and intact %04d (ADR-003)'
        );
        expect_contains($cmd, escapeshellarg('/data/out/task-1/output.m3u8'), 'playlist path must be escaped');
        expect_not_contains($cmd, ' ' . escapeshellarg('/data/out.mp4'), 'hls must not use plain output_path');
    });

    wfs_case('builder: hls without output_base_path rejected', function () use ($makeBuilder) {
        expect_throws(
            fn () => $makeBuilder()->build([
                'output_format' => 'hls',
                'input_path'    => '/data/in.mp4',
            ]),
            '生成 HLS 需要提供输出目录',
            'hls must require output_base_path'
        );
    });

    wfs_case('builder: whitelisted custom_args inserted before output', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + ['custom_args' => '-threads 4 -movflags faststart']);
        expect_contains($cmd, ' -threads ' . escapeshellarg('4'), 'threads value must be escaped');
        expect_contains($cmd, ' -movflags ' . escapeshellarg('faststart'), 'movflags value must be escaped');
        expect_order(
            $cmd,
            ' -threads ',
            ' ' . escapeshellarg('/data/out.mp4'),
            'custom args must be inserted before the output file'
        );
    });

    wfs_case('builder: -vf in custom_args rejected by whitelist', function () use ($makeBuilder, $base) {
        expect_throws(
            fn () => $makeBuilder()->build($base + ['custom_args' => '-vf drawtext=x=10:y=10']),
            '不在白名单',
            'filter injection via custom_args must be rejected'
        );
    });

    wfs_case('builder: -i in custom_args rejected by whitelist', function () use ($makeBuilder, $base) {
        expect_throws(
            fn () => $makeBuilder()->build($base + ['custom_args' => '-i /etc/passwd']),
            '不在白名单',
            'extra input via custom_args must be rejected'
        );
    });

    wfs_case('builder: shell metacharacters rejected by token shape check', function () use ($makeBuilder, $base) {
        expect_throws(
            fn () => $makeBuilder()->build($base + ['custom_args' => '; rm -rf /']),
            '非法 token',
            'shell metacharacters must fail the token shape check'
        );
    });

    wfs_case('builder: non-whitelisted switch rejected even when shape is valid', function () use ($makeBuilder, $base) {
        expect_throws(
            fn () => $makeBuilder()->build($base + ['custom_args' => '-threads4']),
            '不在白名单',
            'unknown switch must be rejected'
        );
    });

    wfs_case('builder: bitrates appear as escaped token pairs', function () use ($makeBuilder, $base) {
        $cmd = $makeBuilder()->build($base + ['video_bitrate' => '2500k', 'audio_bitrate' => '128k']);
        expect_contains($cmd, ' -b:v ' . escapeshellarg('2500k'), 'video bitrate must be escaped');
        expect_contains($cmd, ' -b:a ' . escapeshellarg('128k'), 'audio bitrate must be escaped');
    });
}
