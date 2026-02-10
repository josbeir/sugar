<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use RuntimeException;

/**
 * Helper trait for managing temporary directories in tests
 *
 * Provides DRY methods for creating and cleaning up temp directories
 */
trait TempDirectoryTrait
{
    /**
     * @var array<string> Directories to clean up in tearDown
     */
    private array $tempDirectories = [];

    /**
     * Create a temporary directory
     *
     * @param string $prefix Prefix for directory name
     * @return string Absolute path to created directory
     */
    protected function createTempDir(string $prefix = 'sugar_test_'): string
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $dir = $base . DIRECTORY_SEPARATOR . $prefix . uniqid();
        if (!mkdir($dir, 0755, true)) {
            throw new RuntimeException('Failed to create temporary directory: ' . $dir);
        }

        $this->tempDirectories[] = $dir;

        return realpath($dir) ?: $dir;
    }

    /**
     * Remove a directory recursively
     *
     * @param string $dir Directory path to remove
     */
    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeTempDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Clean up all temporary directories created during test
     *
     * Call this in tearDown() or it will be called automatically
     * if the trait is used in a PHPUnit test case
     */
    protected function cleanupTempDirs(): void
    {
        foreach ($this->tempDirectories as $dir) {
            $this->removeTempDir($dir);
        }

        $this->tempDirectories = [];
    }

    /**
     * Force a file's mtime forward without waiting.
     */
    protected function bumpFileMtime(string $path, int $seconds = 2): void
    {
        $now = time();
        $current = is_file($path) ? filemtime($path) : false;
        $target = $current !== false ? max($current + $seconds, $now + $seconds) : $now + $seconds;

        touch($path, $target);
        clearstatcache();
    }

    /**
     * Automatically clean up temp directories after each test
     * This hooks into PHPUnit's tearDown
     */
    protected function tearDown(): void
    {
        $this->cleanupTempDirs();

        // Call parent tearDown if it exists
        if (method_exists(get_parent_class($this) ?: '', 'tearDown')) {
            parent::tearDown();
        }
    }
}
