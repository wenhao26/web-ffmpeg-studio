<?php
declare(strict_types=1);

function run_input_sanitizer_tests(): void
{
    $sanitizer = new App\Service\InputSanitizer(wfs_config());

    $base = [
        'output_format' => 'mp4',
        'input_path'    => '/data/in.mp4',
        'output_path'   => '/data/out.mp4',
    ];

    wfs_case('sanitizer: missing output_format rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize([
                'input_path'  => $base['input_path'],
                'output_path' => $base['output_path'],
            ]),
            'output_format 为必填项',
            'missing output_format must be rejected'
        );
    });

    wfs_case('sanitizer: invalid output_format rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize(array_merge($base, ['output_format' => 'exe'])),
            '参数 output_format 的值',
            'format outside whitelist must be rejected'
        );
    });

    wfs_case('sanitizer: invalid video_codec rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['video_codec' => 'H264']),
            '参数 video_codec 的值',
            'video codec outside whitelist must be rejected'
        );
    });

    wfs_case('sanitizer: video_codec whitelist is case-sensitive', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['video_codec' => 'Libx264']),
            '参数 video_codec 的值',
            'strict in_array must reject wrong-case values'
        );
    });

    wfs_case('sanitizer: invalid preset rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['preset' => 'turbo']),
            '参数 preset 的值',
            'preset outside whitelist must be rejected'
        );
    });

    wfs_case('sanitizer: invalid audio_codec rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['audio_codec' => 'opusx']),
            '参数 audio_codec 的值',
            'audio codec outside whitelist must be rejected'
        );
    });

    wfs_case('sanitizer: watermark_type none rejected (frontend must omit key)', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['watermark_type' => 'none']),
            '参数 watermark_type 的值',
            'watermark_type none must be rejected; frontend omits the key instead'
        );
    });

    wfs_case('sanitizer: invalid watermark_position rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['watermark_position' => 'middle']),
            '参数 watermark_position 的值',
            'position outside whitelist must be rejected'
        );
    });

    wfs_case('sanitizer: crf 99 out of range', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['crf' => 99]),
            '超出范围 [0, 51]',
            'crf 99 must be rejected'
        );
    });

    wfs_case('sanitizer: crf -5 out of range', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['crf' => -5]),
            '超出范围 [0, 51]',
            'negative crf must be rejected'
        );
    });

    wfs_case('sanitizer: crf 0 is kept (falsy-value regression)', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['crf' => 0]);
        expect_true(array_key_exists('crf', $clean) && $clean['crf'] === 0, 'crf 0 must survive filtering');
    });

    wfs_case('sanitizer: crf 51 upper bound accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['crf' => 51]);
        expect_true(($clean['crf'] ?? null) === 51, 'crf 51 must be accepted');
    });

    wfs_case('sanitizer: crf noise string absorbed by int cast', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['crf' => '23abc']);
        expect_true(array_key_exists('crf', $clean), "'23abc' must be absorbed into range check (documented behavior)");
    });

    wfs_case('sanitizer: crf empty string treated as unset', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['crf' => '']);
        expect_true(!array_key_exists('crf', $clean), "crf '' must be dropped");
    });

    wfs_case('sanitizer: start_time must be HH:MM:SS', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['start_time' => '1:02:03']),
            '时间格式无效',
            'single-digit hour must be rejected'
        );
    });

    wfs_case('sanitizer: start_time with trailing shell meta rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['start_time' => '00:00:10;id']),
            '时间格式无效',
            'shell metacharacters in time must be rejected'
        );
    });

    wfs_case('sanitizer: start_time milliseconds accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['start_time' => '00:00:10.500']);
        expect_true(($clean['start_time'] ?? null) === '00:00:10.500', 'HH:MM:SS.mmm must be accepted');
    });

    wfs_case('sanitizer: end_time invalid format rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['end_time' => '00:00']),
            '时间格式无效',
            'short end_time must be rejected'
        );
    });

    wfs_case('sanitizer: video_bitrate letters rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['video_bitrate' => 'abc']),
            '参数 video_bitrate 的格式无效',
            'non-numeric bitrate must be rejected'
        );
    });

    wfs_case('sanitizer: video_bitrate with unit accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['video_bitrate' => '2500k']);
        expect_true(($clean['video_bitrate'] ?? null) === '2500k', 'bitrate with k unit must be accepted');
    });

    wfs_case('sanitizer: audio_bitrate negative rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['audio_bitrate' => '-1']),
            '参数 audio_bitrate 的格式无效',
            'negative bitrate must be rejected'
        );
    });

    wfs_case('sanitizer: crop with x separator rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['crop' => '1920x1080']),
            '参数 crop 的格式无效',
            "crop 'WxH' must be rejected (needs colon syntax)"
        );
    });

    wfs_case('sanitizer: crop rect accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['crop' => '1920:1080:0:0']);
        expect_true(($clean['crop'] ?? null) === '1920:1080:0:0', 'crop w:h:x:y must be accepted');
    });

    wfs_case('sanitizer: crop incomplete rect rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['crop' => '1920:1080:0']),
            '参数 crop 的格式无效',
            'crop with only one offset must be rejected'
        );
    });

    wfs_case('sanitizer: scale single number rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['scale' => '1280']),
            '参数 scale 的格式无效',
            'scale without height must be rejected'
        );
    });

    wfs_case('sanitizer: scale w:h accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['scale' => '1280:720']);
        expect_true(($clean['scale'] ?? null) === '1280:720', 'scale w:h must be accepted');
    });

    wfs_case('sanitizer: watermark_text over 500 ascii rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['watermark_text' => str_repeat('a', 501)]),
            '超过限制',
            'watermark text of 501 chars must be rejected'
        );
    });

    wfs_case('sanitizer: watermark_text 500 ascii accepted', function () use ($sanitizer, $base) {
        $clean = $sanitizer->sanitize($base + ['watermark_text' => str_repeat('a', 500)]);
        expect_true(isset($clean['watermark_text']), 'watermark text of exactly 500 chars must be accepted');
    });

    wfs_case('sanitizer: watermark_text over 500 CJK chars rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['watermark_text' => str_repeat('字', 501)]),
            '超过限制',
            'CJK watermark text length must be measured in characters (mb_strlen)'
        );
    });

    wfs_case('sanitizer: custom_args over 500 rejected', function () use ($sanitizer, $base) {
        expect_throws(
            fn () => $sanitizer->sanitize($base + ['custom_args' => str_repeat('a', 501)]),
            '超过限制',
            'custom_args of 501 chars must be rejected'
        );
    });

    wfs_case('sanitizer: blanks dropped, falsy and unknown keys kept', function () use ($sanitizer) {
        $clean = $sanitizer->sanitize([
            'output_format' => 'mp4',
            'input_path'    => '/data/in.mp4',
            'start_time'    => '',
            'audio_codec'   => '',
            'crf'           => 0,
            'file_id'       => 'abc-123',
        ]);
        expect_true(!array_key_exists('start_time', $clean), 'blank start_time must be dropped');
        expect_true(!array_key_exists('audio_codec', $clean), 'blank audio_codec must be dropped');
        expect_true(($clean['crf'] ?? null) === 0, 'crf 0 must be kept');
        expect_true(($clean['file_id'] ?? null) === 'abc-123', 'unknown passthrough keys must be kept');
        expect_true(($clean['output_format'] ?? null) === 'mp4', 'output_format must be kept');
    });
}
