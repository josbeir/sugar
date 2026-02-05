<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\TemplateInheritance;

use PHPUnit\Framework\TestCase;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\TemplateInheritance\FileTemplateLoader;

final class FileTemplateLoaderComponentTest extends TestCase
{
    private string $tempDir;

    private FileTemplateLoader $loader;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sugar_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->loader = new FileTemplateLoader((new SugarConfig())->withTemplatePaths($this->tempDir));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testDiscoverComponentsFindsComponentsInDirectory(): void
    {
        // Create component files
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Click</button>');
        file_put_contents($this->tempDir . '/components/s-alert.sugar.php', '<div>Alert</div>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->hasComponent('button'));
        $this->assertTrue($this->loader->hasComponent('alert'));
    }

    public function testDiscoverComponentsRecursivelyFindsNestedComponents(): void
    {
        // Create nested component files
        mkdir($this->tempDir . '/components/forms', 0777, true);
        file_put_contents($this->tempDir . '/components/s-card.sugar.php', '<div>Card</div>');
        file_put_contents($this->tempDir . '/components/forms/s-input.sugar.php', '<input>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->hasComponent('card'));
        $this->assertTrue($this->loader->hasComponent('input'));
    }

    public function testDiscoverComponentsIgnoresFragmentElement(): void
    {
        // Create s-template (should be ignored as it's the fragment element)
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-template.sugar.php', '<div>Template</div>');
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');

        $this->loader->discoverComponents('components');

        $this->assertFalse($this->loader->hasComponent('template'));
        $this->assertTrue($this->loader->hasComponent('button'));
    }

    public function testDiscoverComponentsIgnoresNonSugarFiles(): void
    {
        // Create non-.sugar.php files
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');
        file_put_contents($this->tempDir . '/components/s-alert.php', '<div>Alert</div>');
        file_put_contents($this->tempDir . '/components/button.sugar.php', '<button>No prefix</button>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->hasComponent('button'));
        $this->assertFalse($this->loader->hasComponent('alert'));
    }

    public function testDiscoverComponentsIgnoresFilesWithoutPrefix(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Prefixed</button>');
        file_put_contents($this->tempDir . '/components/button.sugar.php', '<button>Not prefixed</button>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->hasComponent('button'));
        $this->assertSame(1, count($this->loader->getComponents()));
    }

    public function testDiscoverComponentsWithCustomPrefix(): void
    {
        $loader = new FileTemplateLoader(SugarConfig::withPrefix('x')->withTemplatePaths($this->tempDir));

        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/x-button.sugar.php', '<button>Button</button>');
        file_put_contents($this->tempDir . '/components/s-alert.sugar.php', '<div>Alert</div>');

        $loader->discoverComponents('components');

        $this->assertTrue($loader->hasComponent('button'));
        $this->assertFalse($loader->hasComponent('alert'));
    }

    public function testDiscoverComponentsHandlesNonExistentDirectory(): void
    {
        // Should not throw exception
        $this->loader->discoverComponents('non-existent');

        $this->assertSame([], $this->loader->getComponents());
    }

    public function testHasComponentReturnsTrueForDiscoveredComponent(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->hasComponent('button'));
    }

    public function testHasComponentReturnsFalseForUndiscoveredComponent(): void
    {
        $this->assertFalse($this->loader->hasComponent('nonexistent'));
    }

    public function testLoadComponentReturnsComponentContent(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Click Me</button>');

        $this->loader->discoverComponents('components');

        $content = $this->loader->loadComponent('button');

        $this->assertSame('<button>Click Me</button>', $content);
    }

    public function testLoadComponentThrowsExceptionForUndiscoveredComponent(): void
    {
        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "nonexistent" not found');

        $this->loader->loadComponent('nonexistent');
    }

    public function testIsComponentReturnsTrueForComponentElement(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');

        $this->loader->discoverComponents('components');

        $this->assertTrue($this->loader->isComponent('s-button'));
    }

    public function testIsComponentReturnsFalseForFragmentElement(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-template.sugar.php', '<div>Template</div>');

        $this->loader->discoverComponents('components');

        $this->assertFalse($this->loader->isComponent('s-template'));
    }

    public function testIsComponentReturnsFalseForNonPrefixedElement(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');

        $this->loader->discoverComponents('components');

        $this->assertFalse($this->loader->isComponent('button'));
        $this->assertFalse($this->loader->isComponent('div'));
    }

    public function testIsComponentReturnsFalseForUndiscoveredComponent(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');

        $this->loader->discoverComponents('components');

        $this->assertFalse($this->loader->isComponent('s-alert'));
    }

    public function testGetComponentNameExtractsNameFromElementName(): void
    {
        $name = $this->loader->getComponentName('s-button');

        $this->assertSame('button', $name);
    }

    public function testGetComponentNameWorksWithCustomPrefix(): void
    {
        $loader = new FileTemplateLoader(SugarConfig::withPrefix('x')->withTemplatePaths($this->tempDir));

        $name = $loader->getComponentName('x-alert');

        $this->assertSame('alert', $name);
    }

    public function testGetComponentsReturnsAllDiscoveredComponents(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Button</button>');
        file_put_contents($this->tempDir . '/components/s-alert.sugar.php', '<div>Alert</div>');

        $this->loader->discoverComponents('components');

        $components = $this->loader->getComponents();

        $this->assertCount(2, $components);
        $this->assertArrayHasKey('button', $components);
        $this->assertArrayHasKey('alert', $components);
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                $this->removeDirectory($path . '/' . $file);
            }

            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
