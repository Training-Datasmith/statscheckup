<?php

class StatscheckupGuardTest extends StatscheckupTestCase
{
    private static $diagnosticPattern = '/(Notice:|Warning:|Deprecated:|Strict Standards:|Parse error|Fatal error)/';

    public function testModuleFileExitsWhenVersionConstantMissing()
    {
        $modulePath = dirname(dirname(__DIR__)) . '/statscheckup.php';
        $this->runChild('statscheckup_without_version', $modulePath, false);
    }

    public function testModuleFileDefinesClassWhenVersionConstantPresent()
    {
        $modulePath = dirname(dirname(__DIR__)) . '/statscheckup.php';
        $output = $this->runChild('statscheckup_with_version', $modulePath, true);
        $this->assertContains('SENTINEL_AFTER', $output);
        $this->assertContains('CLASS_OK', $output);
    }

    public function testDirectoryIndexScriptsExitCleanly()
    {
        $paths = array(
            'index.php',
            'img/index.php',
            'tests/index.php',
            'tests/phpstan/index.php',
            'translations/index.php',
            'upgrade/index.php',
        );
        $root = dirname(dirname(__DIR__));
        foreach ($paths as $relative) {
            $absolute = $root . '/' . $relative;
            $this->assertFileExists($absolute);
            $output = $this->runChild('include_guard', $absolute, true);
            $this->assertNotContains('SENTINEL_AFTER', $output);
        }
    }

    private function runChild($scenario, $target, $expectSuccess)
    {
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = __DIR__ . '/include-child.php';
        $cmd = escapeshellarg($php)
            . ' -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d html_errors=0 -d log_errors=0 '
            . escapeshellarg($script) . ' '
            . escapeshellarg($scenario) . ' '
            . escapeshellarg($target);
        $descriptor = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $process = proc_open($cmd, $descriptor, $pipes);
        $this->assertInternalType('resource', $process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $combined = $stdout . $stderr;
        $this->assertNotRegExp(self::$diagnosticPattern, $combined);
        if ($expectSuccess) {
            $this->assertSame(0, $status);
        } else {
            if ($scenario === 'statscheckup_without_version') {
                $this->assertNotContains('SENTINEL_AFTER', $stdout);
            }
        }

        return $combined;
    }
}
