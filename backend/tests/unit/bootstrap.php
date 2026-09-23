<?php
declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string): int
    {
        return strlen($string);
    }
}

require __DIR__ . '/../../app/Exception/InvalidArgumentException.php';
require __DIR__ . '/../../app/service/InputSanitizer.php';
require __DIR__ . '/../../app/service/CommandBuilder.php';

$GLOBALS['wfs_config']  = require __DIR__ . '/../../config/ffmpeg.php';
$GLOBALS['wfs_passed']  = 0;
$GLOBALS['wfs_failed']  = 0;
$GLOBALS['wfs_failures'] = [];

function wfs_config(): array
{
    return $GLOBALS['wfs_config'];
}

function wfs_case(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['wfs_passed']++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $e) {
        $GLOBALS['wfs_failed']++;
        $GLOBALS['wfs_failures'][] = "{$name}: {$e->getMessage()}";
        fwrite(STDOUT, "FAIL  {$name}: {$e->getMessage()}\n");
    }
}

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_contains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' | missing: ' . $needle);
    }
}

function expect_not_contains(string $haystack, string $needle, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' | unexpected: ' . $needle);
    }
}

function expect_order(string $haystack, string $first, string $second, string $message): void
{
    $ia = strpos($haystack, $first);
    $ib = strpos($haystack, $second);
    if ($ia === false || $ib === false) {
        throw new RuntimeException($message . ' | not found: ' . ($ia === false ? $first : $second));
    }
    if ($ia >= $ib) {
        throw new RuntimeException($message . " | '{$first}' (at {$ia}) must precede '{$second}' (at {$ib})");
    }
}

function expect_throws(callable $fn, string $messageNeedle, string $message): void
{
    try {
        $fn();
    } catch (App\Exception\InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $messageNeedle)) {
            throw new RuntimeException(
                $message . ' | got message: ' . $e->getMessage() . ' | want fragment: ' . $messageNeedle
            );
        }
        return;
    }
    throw new RuntimeException($message . ' | expected InvalidArgumentException, none thrown');
}

function wfs_summary(): int
{
    $passed = $GLOBALS['wfs_passed'];
    $failed = $GLOBALS['wfs_failed'];

    fwrite(STDOUT, "------\n");
    fwrite(STDOUT, "PASS: {$passed}  FAIL: {$failed}\n");

    return $failed > 0 ? 1 : 0;
}
