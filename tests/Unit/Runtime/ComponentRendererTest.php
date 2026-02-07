<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class ComponentRendererTest extends TestCase
{
    use CompilerTestTrait;
    use TempDirectoryTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompiler(
            config: new SugarConfig(),
            withTemplateLoader: true,
            templatePaths: [SUGAR_TEST_TEMPLATES_PATH],
            componentPaths: ['components'],
        );
    }

    public function testRenderComponentThrowsForEmptyName(): void
    {
        $renderer = $this->createRenderer();

        $this->expectException(ComponentNotFoundException::class);

        $renderer->renderComponent('');
    }

    public function testRenderComponentMergesSlotsAndAttributes(): void
    {
        $renderer = $this->createRenderer();

        $output = $renderer->renderComponent(
            name: 'alert',
            vars: ['title' => 'Warning'],
            slots: ['slot' => 'Save'],
            attributes: ['class' => 'large', 'data-extra' => 'yes'],
        );

        $this->assertStringContainsString('<div', $output);
        $this->assertStringContainsString('class="alert alert-info large"', $output);
        $this->assertStringContainsString('data-extra="yes"', $output);
        $this->assertStringContainsString('Save', $output);
        $this->assertStringContainsString('Warning', $output);
    }

    private function createRenderer(): ComponentRenderer
    {
        $cacheDir = $this->createTempDir('sugar_cache_');
        $cache = new FileCache($cacheDir);

        $loader = $this->templateLoader;
        $this->assertInstanceOf(FileTemplateLoader::class, $loader);

        return new ComponentRenderer(
            compiler: $this->compiler,
            loader: $loader,
            cache: $cache,
        );
    }
}
