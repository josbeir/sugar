<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\TemplateInheritance;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\TemplateNotFoundException;
use Sugar\TemplateInheritance\FileTemplateLoader;

final class FileTemplateLoaderTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../../fixtures/templates/template-inheritance';
    }

    public function testLoadsTemplateFromBasePath(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $content = $loader->load('base.sugar.php');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testThrowsExceptionWhenTemplateNotFound(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $loader->load('nonexistent.sugar.php');
    }

    public function testResolvesAbsolutePath(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $resolved = $loader->resolve('/layouts/base.sugar.php', '');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testResolvesRelativePathFromCurrentTemplate(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $resolved = $loader->resolve('header.sugar.php', 'pages/home.sugar.php');

        $this->assertSame('pages/header.sugar.php', $resolved);
    }

    public function testResolvesParentDirectoryPath(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $resolved = $loader->resolve('../layouts/base.sugar.php', 'pages/home.sugar.php');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testNormalizesPathWithMultipleDots(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $resolved = $loader->resolve('../../layouts/base.sugar.php', 'pages/admin/dashboard.sugar.php');

        $this->assertSame('layouts/base.sugar.php', $resolved);
    }

    public function testLoadsTemplateWithoutExtension(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $content = $loader->load('base');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testLoadsTemplateWithoutExtensionFromSubdirectory(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $content = $loader->load('layouts/base');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testPrefersExactPathOverExtensionAddition(): void
    {
        // Create a file without extension
        file_put_contents($this->fixturesPath . '/test', 'content without extension');
        file_put_contents($this->fixturesPath . '/test.sugar.php', 'content with extension');

        $loader = new FileTemplateLoader($this->fixturesPath);

        $content = $loader->load('test');

        $this->assertSame('content without extension', $content);

        // Cleanup
        unlink($this->fixturesPath . '/test');
        unlink($this->fixturesPath . '/test.sugar.php');
    }

    public function testThrowsExceptionWhenTemplateNotFoundWithOrWithoutExtension(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('not found at path');

        $loader->load('nonexistent');
    }
}
