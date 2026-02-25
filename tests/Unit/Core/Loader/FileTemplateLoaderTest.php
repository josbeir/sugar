<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\TemplateNamespaceDefinition;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class FileTemplateLoaderTest extends TestCase
{
    use TempDirectoryTrait;

    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
    }

    public function testLoadsTemplateFromDefaultAppNamespace(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $content = $loader->load('base.sugar.php');

        $this->assertStringContainsString('<title s:block="title">Base Title</title>', $content);
    }

    public function testResolveReturnsCanonicalNamespacedName(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $resolved = $loader->resolve('../layouts/base.sugar.php', '@app/pages/home.sugar.php');

        $this->assertSame('@app/layouts/base.sugar.php', $resolved);
    }

    public function testThrowsWhenTemplateNotFound(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->expectException(TemplateNotFoundException::class);

        $loader->load('@app/nonexistent.sugar.php');
    }

    public function testExistsReflectsTemplateAvailability(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->assertTrue($loader->exists('base.sugar.php'));
        $this->assertFalse($loader->exists('missing.sugar.php'));
    }

    public function testLoadsFromRegisteredNamespaceRoots(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition([
            SUGAR_TEST_TEMPLATES_PATH . '/components',
        ]));

        $content = $loader->load('@components/s-button');

        $this->assertStringContainsString('<button', $content);
    }

    public function testSourcePathReturnsAbsolutePathAndSourceIdPrefersPath(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $sourcePath = $loader->sourcePath('base.sugar.php');

        $this->assertIsString($sourcePath);
        $this->assertSame($sourcePath, $loader->sourceId('base.sugar.php'));
    }

    public function testLoadsTemplateWithConstructorConfiguredSuffixes(): void
    {
        $tempDir = $this->createTempDir('sugar_test_');
        file_put_contents($tempDir . '/custom.sugar.tpl', '<div>Custom</div>');

        $loader = new FileTemplateLoader([$tempDir], false, ['.sugar.tpl']);

        $this->assertSame('<div>Custom</div>', $loader->load('custom'));

        unlink($tempDir . '/custom.sugar.tpl');
        $this->removeTempDir($tempDir);
    }

    public function testDiscoverReturnsCanonicalSortedNames(): void
    {
        $tempDir1 = $this->createTempDir('sugar_test_');
        $tempDir2 = $this->createTempDir('sugar_test_');

        mkdir($tempDir1 . '/components/forms', 0777, true);
        mkdir($tempDir2 . '/components', 0777, true);

        file_put_contents($tempDir1 . '/components/s-alert.sugar.php', '<div>alert</div>');
        file_put_contents($tempDir1 . '/components/forms/s-input.sugar.php', '<input>');
        file_put_contents($tempDir2 . '/components/s-alert.sugar.php', '<div>alert v2</div>');

        $loader = new FileTemplateLoader([$tempDir1, $tempDir2]);

        $this->assertSame([
            '@app/components/forms/s-input.sugar.php',
            '@app/components/s-alert.sugar.php',
        ], $loader->discover('app', 'components'));

        unlink($tempDir1 . '/components/s-alert.sugar.php');
        unlink($tempDir1 . '/components/forms/s-input.sugar.php');
        unlink($tempDir2 . '/components/s-alert.sugar.php');
        $this->removeTempDir($tempDir1);
        $this->removeTempDir($tempDir2);
    }

    public function testGetRegisteredNamespacesReturnsAllRegisteredNamespaces(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->assertSame(['app'], $loader->getRegisteredNamespaces());
    }

    public function testGetRegisteredNamespacesWithMultipleNamespacesReturnsAll(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);
        $loader->registerNamespace('plugin-auth', new TemplateNamespaceDefinition([
            SUGAR_TEST_TEMPLATES_PATH . '/plugins/auth',
        ]));
        $loader->registerNamespace('shared-ui', new TemplateNamespaceDefinition([
            SUGAR_TEST_TEMPLATES_PATH . '/shared',
        ]));

        $namespaces = $loader->getRegisteredNamespaces();

        $this->assertContains('app', $namespaces);
        $this->assertContains('plugin-auth', $namespaces);
        $this->assertContains('shared-ui', $namespaces);
        $this->assertCount(3, $namespaces);
    }

    public function testConstructorWithoutTemplatePathsFallsBackToCurrentWorkingDirectory(): void
    {
        $tempDir = $this->createTempDir('sugar_test_');
        file_put_contents($tempDir . '/cwd-template.sugar.php', '<div>Cwd</div>');

        $previousCwd = getcwd();
        chdir($tempDir);

        try {
            $loader = new FileTemplateLoader();
            $this->assertSame('<div>Cwd</div>', $loader->load('cwd-template.sugar.php'));
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }

            unlink($tempDir . '/cwd-template.sugar.php');
            $this->removeTempDir($tempDir);
        }
    }

    public function testSourceIdFallsBackToResolvedCanonicalNameWhenTemplateDoesNotExist(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->assertSame('@app/missing/path', $loader->sourceId('missing/path'));
    }

    public function testLoadWithAbsolutePathLikeInputThrowsWhenNotResolvable(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->expectException(TemplateNotFoundException::class);

        $loader->load('/tmp/absolute-template.sugar.php');
    }

    public function testDiscoverSkipsMissingRootsAndAppliesPrefixFilter(): void
    {
        $tempDir = $this->createTempDir('sugar_test_');
        mkdir($tempDir . '/components', 0777, true);
        file_put_contents($tempDir . '/components/s-card.sugar.php', '<div>Card</div>');
        file_put_contents($tempDir . '/standalone.sugar.php', '<div>Standalone</div>');

        $loader = new FileTemplateLoader([$this->fixturesPath]);
        $loader->registerNamespace('custom', new TemplateNamespaceDefinition([
            $tempDir,
            $tempDir . '/missing-root',
        ]));

        $this->assertSame([
            '@custom/components/s-card.sugar.php',
        ], $loader->discover('custom', 'components'));

        unlink($tempDir . '/components/s-card.sugar.php');
        unlink($tempDir . '/standalone.sugar.php');
        $this->removeTempDir($tempDir);
    }

    public function testDiscoverReturnsEmptyArrayForUnknownNamespace(): void
    {
        $loader = new FileTemplateLoader([$this->fixturesPath]);

        $this->assertSame([], $loader->discover('unknown'));
    }
}
