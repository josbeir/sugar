<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\TemplateInheritance;

use PHPUnit\Framework\TestCase;
use Sugar\Config\SugarConfig;
use Sugar\Exception\TemplateNotFoundException;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class FileTemplateLoaderTest extends TestCase
{
    use TempDirectoryTrait;

    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
    }

    public function testLoadsTemplateFromBasePath(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $content = $loader->load('base.sugar.php');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testThrowsExceptionWhenTemplateNotFound(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $loader->load('nonexistent.sugar.php');
    }

    public function testResolvesAbsolutePath(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $resolved = $loader->resolve('/layouts/base.sugar.php', '');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testResolvesRelativePathFromCurrentTemplate(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $resolved = $loader->resolve('header.sugar.php', 'pages/home.sugar.php');

        $this->assertSame('pages/header.sugar.php', $resolved);
    }

    public function testResolvesParentDirectoryPath(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $resolved = $loader->resolve('../layouts/base.sugar.php', 'pages/home.sugar.php');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testNormalizesPathWithMultipleDots(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $resolved = $loader->resolve('../../layouts/base.sugar.php', 'pages/admin/dashboard.sugar.php');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testLoadsTemplateWithoutExtension(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $content = $loader->load('base');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testLoadsTemplateWithoutExtensionFromSubdirectory(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $content = $loader->load('layouts/base');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testLoadsTemplateWithCustomSuffix(): void
    {
        $tempDir = $this->createTempDir('sugar_test_');
        $path = $tempDir . '/custom.sugar.tpl';
        file_put_contents($path, '<div>Custom</div>');

        $config = (new SugarConfig())->withFileSuffix('.sugar.tpl');
        $loader = new FileTemplateLoader($config, [$tempDir]);

        $content = $loader->load('custom');

        $this->assertSame('<div>Custom</div>', $content);

        unlink($path);
    }

    public function testPrefersExactPathOverExtensionAddition(): void
    {
        // Create a file without extension
        file_put_contents($this->fixturesPath . '/test', 'content without extension');
        file_put_contents($this->fixturesPath . '/test.sugar.php', 'content with extension');

        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $content = $loader->load('test');

        $this->assertSame('content without extension', $content);

        // Cleanup
        unlink($this->fixturesPath . '/test');
        unlink($this->fixturesPath . '/test.sugar.php');
    }

    public function testThrowsExceptionWhenTemplateNotFoundWithOrWithoutExtension(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig(), [$this->fixturesPath]);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('not found in paths');

        $loader->load('nonexistent');
    }

    public function testLoadsFromMultipleTemplatePaths(): void
    {
        $tempDir1 = $this->createTempDir('sugar_test_');
        $tempDir2 = $this->createTempDir('sugar_test_');

        // Create template in second path
        file_put_contents($tempDir2 . '/test.sugar.php', '<div>From path 2</div>');

        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir1, $tempDir2]);

        $content = $loader->load('test.sugar.php');

        $this->assertSame('<div>From path 2</div>', $content);

        // Cleanup
        unlink($tempDir2 . '/test.sugar.php');
    }

    public function testLoadThrowsWhenFileIsUnreadable(): void
    {
        $tempDir = $this->createTempDir('sugar_test_');
        $path = $tempDir . '/unreadable.sugar.php';
        file_put_contents($path, 'content');
        chmod($path, 0000);

        if (is_readable($path)) {
            $this->markTestSkipped('Unable to make file unreadable on this platform.');
        }

        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);

        set_error_handler(static fn() => true);

        try {
            $this->expectException(TemplateNotFoundException::class);
            $this->expectExceptionMessage('Failed to read template "unreadable.sugar.php"');

            $loader->load('unreadable.sugar.php');
        } finally {
            if (is_file($path)) {
                chmod($path, 0644);
                unlink($path);
            }

            restore_error_handler();
        }
    }

    public function testFirstPathTakesPrecedenceInMultiplePaths(): void
    {
        $tempDir1 = $this->createTempDir('sugar_test_');
        $tempDir2 = $this->createTempDir('sugar_test_');

        // Create same template in both paths
        file_put_contents($tempDir1 . '/test.sugar.php', '<div>From path 1</div>');
        file_put_contents($tempDir2 . '/test.sugar.php', '<div>From path 2</div>');

        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir1, $tempDir2]);

        $content = $loader->load('test.sugar.php');

        // Should load from first path
        $this->assertSame('<div>From path 1</div>', $content);

        // Cleanup
        unlink($tempDir1 . '/test.sugar.php');
        unlink($tempDir2 . '/test.sugar.php');
    }

    public function testEmptyTemplatePathsDefaultsToCurrentDirectory(): void
    {
        $loader = new FileTemplateLoader(new SugarConfig());

        // Should use getcwd() as default
        // We can't easily test this without changing the current directory
        // This test just verifies it doesn't throw an exception
        $this->expectException(TemplateNotFoundException::class);
        $loader->load('nonexistent-template-that-should-not-exist.sugar.php');
    }

    public function testMultiplePathsInExceptionMessage(): void
    {
        $tempDir1 = $this->createTempDir('sugar_test_');
        $tempDir2 = $this->createTempDir('sugar_test_');

        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir1, $tempDir2]);

        try {
            $loader->load('nonexistent.sugar.php');
            $this->fail('Expected TemplateNotFoundException');
        } catch (TemplateNotFoundException $templateNotFoundException) {
            $this->assertStringContainsString($tempDir1, $templateNotFoundException->getMessage());
            $this->assertStringContainsString($tempDir2, $templateNotFoundException->getMessage());
            $this->assertStringContainsString('not found in paths:', $templateNotFoundException->getMessage());
        }
    }
}
