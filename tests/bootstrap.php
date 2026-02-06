<?php
declare(strict_types=1);

/**
 * Test suite bootstrap
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Define fixture path constants
define('SUGAR_TEST_FIXTURES_PATH', __DIR__ . '/fixtures');
define('SUGAR_TEST_TEMPLATES_PATH', SUGAR_TEST_FIXTURES_PATH . '/templates');
define('SUGAR_TEST_TEMPLATE_INHERITANCE_PATH', SUGAR_TEST_TEMPLATES_PATH . '/template-inheritance');
define('SUGAR_TEST_COMPONENTS_PATH', SUGAR_TEST_TEMPLATES_PATH . '/components');

// Clear stale cache files from previous test runs or experiments
$cachePattern = sys_get_temp_dir() . '/sugar_cache_*';
$cacheDirs = glob($cachePattern);
if ($cacheDirs !== false) {
    foreach ($cacheDirs as $cacheDir) {
        if (is_dir($cacheDir)) {
            // Recursively remove cache directory
            $removeDir = function (string $dir) use (&$removeDir): void {
                if (!is_dir($dir)) {
                    return;
                }

                $files = array_diff(scandir($dir) ?: [], ['.', '..']);
                foreach ($files as $file) {
                    $path = $dir . '/' . $file;
                    is_dir($path) ? $removeDir($path) : unlink($path);
                }

                rmdir($dir);
            };
            $removeDir($cacheDir);
        }
    }
}

// Clear test tmp directory
$testTmpDir = __DIR__ . '/tmp';
if (is_dir($testTmpDir)) {
    $removeDir = function (string $dir) use (&$removeDir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $removeDir($path) : unlink($path);
        }

        rmdir($dir);
    };
    $removeDir($testTmpDir);
}
