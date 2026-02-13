<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Exception\TemplateRuntimeException;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Runtime\ComponentRenderer;
use Sugar\Runtime\RuntimeEnvironment;
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

        RuntimeEnvironment::getRenderer();
    }

    public function testSetAndClearRenderer(): void
    {
        $renderer = $this->createRenderer();

        RuntimeEnvironment::setRenderer($renderer);

        $this->assertSame($renderer, RuntimeEnvironment::getRenderer());

        RuntimeEnvironment::clearRenderer();

        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::getRenderer();
    }

    public function testSetAndClearRuntimeEnvironment(): void
    {
        $renderer = $this->createRenderer();
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::set($renderer, $fragmentCache);

        $this->assertSame($renderer, RuntimeEnvironment::getRenderer());
        $this->assertSame($fragmentCache, RuntimeEnvironment::getFragmentCache());

        RuntimeEnvironment::clear();

        $this->assertNull(RuntimeEnvironment::getFragmentCache());
        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::getRenderer();
    }

    public function testSetAndClearFragmentCache(): void
    {
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::setFragmentCache($fragmentCache);
        $this->assertSame($fragmentCache, RuntimeEnvironment::getFragmentCache());

        RuntimeEnvironment::clearFragmentCache();
        $this->assertNull(RuntimeEnvironment::getFragmentCache());
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
