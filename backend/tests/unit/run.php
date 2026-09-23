<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/InputSanitizerTest.php';
require __DIR__ . '/CommandBuilderTest.php';

fwrite(STDOUT, "Web FFmpeg Studio — unit tests (no vendor / no FFmpeg binary required)\n");

run_input_sanitizer_tests();
run_command_builder_tests();

exit(wfs_summary());
