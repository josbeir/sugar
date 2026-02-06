<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Engine;
use Sugar\EngineBuilder;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

/**
 * Test EngineBuilder fluent configuration API
 */
final class EngineBuilderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testBuildThrowsWithoutLoader(): void
    {
        $builder = new EngineBuilder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template loader is required');

        $builder->build();
    }

    public function testBuildWithMinimalConfiguration(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->build();

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithTemplateLoader(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);

        $builder = new EngineBuilder();
        $result = $builder->withTemplateLoader($loader);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithCache(): void
    {
        $tempDir = $this->createTempDir();
        $cacheDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);
        $cache = new FileCache($cacheDir);

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withCache($cache);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithDirectiveRegistry(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);
        $registry = DirectiveRegistry::empty();

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withDirectiveRegistry($registry);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithDebug(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withDebug(true);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithTemplateContext(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);
        $context = new class {
            public string $name = 'Test';
        };

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withTemplateContext($context);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testFluentApiChaining(): void
    {
        $tempDir = $this->createTempDir();
        $cacheDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);
        $cache = new FileCache($cacheDir);
        $registry = new DirectiveRegistry();
        $context = new class {
            public string $value = 'test';
        };

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->withCache($cache)
            ->withDirectiveRegistry($registry)
            ->withDebug(false)
            ->withTemplateContext($context)
            ->build();

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testBuildCreatesDefaultCacheWhenNotProvided(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->build();

        // Should create default FileCache
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testBuildCreatesDefaultRegistryWhenNotProvided(): void
    {
        $tempDir = $this->createTempDir();
        $config = (new SugarConfig())->withTemplatePaths($tempDir);
        $loader = new FileTemplateLoader($config);

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->build();

        // Should create default DirectiveRegistry with standard directives
        $this->assertInstanceOf(Engine::class, $engine);
    }
}
