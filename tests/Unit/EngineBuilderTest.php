<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Cache\FileCache;
use Sugar\Config\SugarConfig;
use Sugar\Engine;
use Sugar\Engine\EngineBuilder;
use Sugar\Exception\Renderer\TemplateExceptionRendererInterface;
use Sugar\Exception\SugarException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\RegistrationContext;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Loader\StringTemplateLoader;
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
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->build();

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithTemplateLoader(): void
    {
        $tempDir = $this->createTempDir();
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);

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
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);
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
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);
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
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);

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
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);
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

    public function testWithExceptionRenderer(): void
    {
        $loader = new StringTemplateLoader(new SugarConfig());
        $renderer = new class implements TemplateExceptionRendererInterface {
            public function render(SugarException $exception): string
            {
                return $exception->getMessage();
            }
        };

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withExceptionRenderer($renderer);

        $this->assertSame($builder, $result);
        $this->assertInstanceOf(Engine::class, $builder->build());
    }

    public function testWithHtmlExceptionRendererRequiresLoader(): void
    {
        $builder = new EngineBuilder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template loader is required before configuring HTML exception renderer');

        $builder->withHtmlExceptionRenderer();
    }

    public function testWithHtmlExceptionRendererUsesBuilderLoader(): void
    {
        $loader = new StringTemplateLoader(new SugarConfig());
        $loader->addTemplate('broken.sugar.php', '<div s:unknown="true">Hello</div>');

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->withDebug(true)
            ->withHtmlExceptionRenderer()
            ->build();

        $output = $engine->render('broken.sugar.php');

        $this->assertStringContainsString('sugar-exception', $output);
        $this->assertStringContainsString('s:unknown=&quot;true&quot;', $output);
    }

    public function testWithHtmlExceptionRendererAcceptsRendererOptions(): void
    {
        $loader = new StringTemplateLoader(new SugarConfig());
        $loader->addTemplate('broken-options.sugar.php', '<div s:unknown="true">Hello</div>');

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->withDebug(true)
            ->withHtmlExceptionRenderer(includeStyles: false, wrapDocument: true)
            ->build();

        $output = $engine->render('broken-options.sugar.php');

        $this->assertStringContainsString('<!doctype html>', $output);
        $this->assertStringNotContainsString('<style>', $output);
        $this->assertStringContainsString('sugar-exception', $output);
    }

    public function testFluentApiChaining(): void
    {
        $tempDir = $this->createTempDir();
        $cacheDir = $this->createTempDir();
        $loader = new FileTemplateLoader(new SugarConfig(), [$tempDir]);
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

    public function testWithExtension(): void
    {
        $loader = new StringTemplateLoader(new SugarConfig());

        $extension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                // no-op extension for testing
            }
        };

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withExtension($extension);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testMultipleExtensions(): void
    {
        $loader = new StringTemplateLoader(new SugarConfig());

        $ext1 = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
            }
        };

        $ext2 = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
            }
        };

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->withExtension($ext1)
            ->withExtension($ext2)
            ->build();

        $this->assertInstanceOf(Engine::class, $engine);
    }
}
