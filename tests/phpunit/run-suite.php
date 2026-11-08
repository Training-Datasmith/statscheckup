<?php

$expectedFiles = array(
    'StatscheckupGuardTest.php',
    'StatscheckupHookTest.php',
    'StatscheckupInstallTest.php',
    'StatscheckupScoringTest.php',
    'StatscheckupUpgradeTest.php',
);
$expectedTestCount = 33;
$shuffleSeed = 20261108;
$phpunitPhar = getenv('STATSCHECKUP_PHPUNIT_PHAR');
if (!$phpunitPhar) {
    fwrite(STDERR, "STATSCHECKUP_PHPUNIT_PHAR is not set\n");
    exit(1);
}

if (PHP_VERSION !== '5.6.40') {
    fwrite(STDERR, 'Unsupported PHP version: ' . PHP_VERSION . "\n");
    exit(1);
}
if (!extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "Required extensions pdo_mysql and mbstring must be loaded\n");
    exit(1);
}

$testDir = __DIR__;
$found = array();
foreach (scandir($testDir) as $entry) {
    if (substr($entry, -8) === 'Test.php') {
        $found[] = $entry;
    }
}
sort($found);
if ($found !== $expectedFiles) {
    fwrite(STDERR, "Unexpected test inventory\n");
    fwrite(STDERR, 'Expected: ' . implode(', ', $expectedFiles) . "\n");
    fwrite(STDERR, 'Found: ' . implode(', ', $found) . "\n");
    exit(1);
}

function statscheckup_wait_for_database()
{
    $attempts = 0;
    while ($attempts < 60) {
        try {
            statscheckup_test_pdo()->query('SELECT 1');

            return;
        } catch (Exception $e) {
            ++$attempts;
            usleep(500000);
        }
    }
    throw new RuntimeException('Database did not become ready');
}

function statscheckup_build_phpunit_xml($files, $tempDir)
{
    global $testDir;
    $xmlPath = $tempDir . '/phpunit.xml';
    $lines = array(
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<phpunit colors="false" bootstrap="' . __DIR__ . '/bootstrap.php">',
        '  <testsuites>',
        '    <testsuite name="module-tests">',
    );
    foreach ($files as $file) {
        $lines[] = '      <file>' . $testDir . '/' . $file . '</file>';
    }
    $lines[] = '    </testsuite>';
    $lines[] = '  </testsuites>';
    $lines[] = '</phpunit>';

    file_put_contents($xmlPath, implode("\n", $lines) . "\n");

    return $xmlPath;
}

function statscheckup_assert_clean_output($output, $label)
{
    $patterns = array(
        '/(Notice:|Warning:|Deprecated:|Strict Standards:|Parse error|Fatal error)/',
        '/FAILURES!/',
        '/OK, but incomplete, skipped, or risky tests!/',
        '/\bSkipped:\b/',
        '/\bRisky:\b/',
        '/\bIncomplete:\b/',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $output)) {
            fwrite(STDERR, $label . " output failed diagnostic check\n");
            fwrite(STDERR, $output);
            exit(1);
        }
    }
}

function statscheckup_run_phpunit($xmlPath, $label)
{
    global $phpunitPhar, $expectedTestCount;
    $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php)
        . ' -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d html_errors=0 -d date.timezone=UTC '
        . escapeshellarg($phpunitPhar)
        . ' --configuration ' . escapeshellarg($xmlPath);
    $descriptor = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $process = proc_open($cmd, $descriptor, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start PHPUnit');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $output = $stdout . $stderr;
  /** @var int $status */
    if ($status !== 0) {
        fwrite(STDERR, $label . " exited with status $status\n");
        fwrite(STDERR, $output);
        exit(1);
    }
    statscheckup_assert_clean_output($output, $label);
    if (!preg_match('/OK \(' . $expectedTestCount . ' tests, (\d+) assertions\)\s*$/', trim($output), $matches)) {
        fwrite(STDERR, $label . " missing exact OK summary\n");
        fwrite(STDERR, $output);
        exit(1);
    }

    return (int) $matches[1];
}

require_once __DIR__ . '/harness.php';
statscheckup_wait_for_database();

$tempDir = sys_get_temp_dir() . '/statscheckup-phpunit-' . getmypid();
if (!mkdir($tempDir, 0700) && !is_dir($tempDir)) {
    fwrite(STDERR, "Failed to create temp directory\n");
    exit(1);
}

$sortedXml = statscheckup_build_phpunit_xml($expectedFiles, $tempDir);
$sortedAssertions = statscheckup_run_phpunit($sortedXml, 'sorted');

$shuffled = $expectedFiles;
srand($shuffleSeed);
shuffle($shuffled);
if ($shuffled === $expectedFiles) {
    fwrite(STDERR, "Shuffle seed did not change order\n");
    exit(1);
}
$shuffledXml = statscheckup_build_phpunit_xml($shuffled, $tempDir);
$shuffledAssertions = statscheckup_run_phpunit($shuffledXml, 'shuffled');

if ($sortedAssertions !== $shuffledAssertions || $sortedAssertions < 1) {
    fwrite(STDERR, "Assertion count drift between runs\n");
    exit(1);
}

$delete = function ($path) {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path)) {
        unlink($path);

        return;
    }
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            $delete($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
};
$delete($tempDir);

echo "OK ({$expectedTestCount} tests, {$sortedAssertions} assertions)\n";
echo "shuffle_seed={$shuffleSeed}\n";
echo "sorted_files=" . implode(',', $expectedFiles) . "\n";
echo "shuffled_files=" . implode(',', $shuffled) . "\n";
