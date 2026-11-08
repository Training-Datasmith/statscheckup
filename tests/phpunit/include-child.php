<?php

$scenario = isset($argv[1]) ? $argv[1] : '';
$target = isset($argv[2]) ? $argv[2] : '';

if ($scenario === 'statscheckup_without_version') {
    include $target;
    echo "SENTINEL_AFTER\n";
    exit(0);
}

if ($scenario === 'statscheckup_with_version') {
    define('_PS_VERSION_', '1.7.1.0');
    class Module
    {
    }
    include $target;
    echo "SENTINEL_AFTER\n";
    echo class_exists('statscheckup', false) ? "CLASS_OK\n" : "CLASS_MISSING\n";
    exit(0);
}

if ($scenario === 'include_guard') {
    if ($target === '') {
        fwrite(STDERR, "Missing include target\n");
        exit(1);
    }
    include $target;
    echo "SENTINEL_AFTER\n";
    exit(0);
}

fwrite(STDERR, "Unknown scenario\n");
exit(1);
