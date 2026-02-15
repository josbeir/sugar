<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\TemplateInheritance;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Loader\ResourceLocatorComponentTemplateLoader;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class FileTemplateLoaderComponentTest extends TestCase
{
    use TempDirectoryTrait;

    private string $tempDir;

    private ResourceLocatorComponentTemplateLoader $loader;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir('sugar_component_test_');
        $templateLoader = new FileTemplateLoader(new SugarConfig(), [$this->tempDir]);
        $this->loader = ResourceLocatorComponentTemplateLoader::forTemplateLoader(
            templateLoader: $templateLoader,
            config: new SugarConfig(),
            directories: ['components'],
        );
    }

    public function testLoadsComponentFromComponentDirectory(): void
    {
        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/s-button.sugar.php', '<button>Click</button>');
        $this->assertSame('<button>Click</button>', $this->loader->loadComponent('button'));
    }

    public function testGetComponentPathReturnsLogicalPath(): void
    {
        mkdir($this->tempDir . '/components/forms', 0777, true);
        file_put_contents($this->tempDir . '/components/forms/s-input.sugar.php', '<input>');

        $this->assertSame('components/forms/s-input.sugar.php', $this->loader->getComponentPath('input'));
    }

    public function testSupportsCustomElementPrefix(): void
    {
        $config = SugarConfig::withPrefix('x');
        $templateLoader = new FileTemplateLoader($config, [$this->tempDir]);
        $loader = ResourceLocatorComponentTemplateLoader::forTemplateLoader(
            templateLoader: $templateLoader,
            config: $config,
            directories: ['components'],
        );

        mkdir($this->tempDir . '/components', 0777, true);
        file_put_contents($this->tempDir . '/components/x-alert.sugar.php', '<div>Alert</div>');

        $this->assertSame('<div>Alert</div>', $loader->loadComponent('alert'));
    }

    public function testGetComponentPathThrowsExceptionForUnknownComponent(): void
    {
        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "unknown" not found');

        $this->loader->getComponentPath('unknown');
    }
}
