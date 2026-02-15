<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Runtime\ComponentRenderer;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

final class RuntimeEnvironmentTest extends TestCase
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

    protected function tearDown(): void
    {
        RuntimeEnvironment::clear();
        $this->cleanupTempDirs();
        parent::tearDown();
    }

    public function testGetRendererThrowsWhenUnset(): void
    {
        RuntimeEnvironment::clear();

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Runtime service "renderer.component" is not initialized.');

        RuntimeEnvironment::requireService(RuntimeEnvironment::RENDERER_SERVICE_ID);
    }

    public function testSetAndClearRendererService(): void
    {
        $renderer = $this->createRenderer();

        RuntimeEnvironment::setService(RuntimeEnvironment::RENDERER_SERVICE_ID, $renderer);

        $this->assertSame($renderer, RuntimeEnvironment::requireService(RuntimeEnvironment::RENDERER_SERVICE_ID));

        RuntimeEnvironment::clearService(RuntimeEnvironment::RENDERER_SERVICE_ID);

        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService(RuntimeEnvironment::RENDERER_SERVICE_ID);
    }

    public function testSetAndClearRuntimeEnvironment(): void
    {
        $renderer = $this->createRenderer();
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::set($renderer, ['cache.fragment' => $fragmentCache]);

        $this->assertSame($renderer, RuntimeEnvironment::requireService(RuntimeEnvironment::RENDERER_SERVICE_ID));
        $this->assertSame($fragmentCache, RuntimeEnvironment::getService('cache.fragment'));

        RuntimeEnvironment::clear();

        $this->assertNull(RuntimeEnvironment::getService('cache.fragment'));
        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService(RuntimeEnvironment::RENDERER_SERVICE_ID);
    }

    public function testSetAndClearService(): void
    {
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::setService('cache.fragment', $fragmentCache);
        $this->assertTrue(RuntimeEnvironment::hasService('cache.fragment'));
        $this->assertSame($fragmentCache, RuntimeEnvironment::getService('cache.fragment'));

        RuntimeEnvironment::clearService('cache.fragment');
        $this->assertFalse(RuntimeEnvironment::hasService('cache.fragment'));
        $this->assertNull(RuntimeEnvironment::getService('cache.fragment'));
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
