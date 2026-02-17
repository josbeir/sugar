<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit;

use PhpParser\Error;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Engine\EngineBuilder;
use Sugar\Core\Exception\CompilationException;
use Sugar\Core\Exception\Renderer\TemplateExceptionRendererInterface;
use Sugar\Core\Exception\SugarException;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;
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
        $loader = new FileTemplateLoader([$tempDir]);

        $builder = new EngineBuilder();
        $engine = $builder
            ->withTemplateLoader($loader)
            ->build();

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testBuildUsesBuilderConfigForParser(): void
    {
        $config = SugarConfig::withPrefix('x');
        $loader = new StringTemplateLoader([
            'custom-prefix.sugar.php' => '<div x:if="$show">Visible</div>',
        ]);

        $engine = (new EngineBuilder($config))
            ->withTemplateLoader($loader)
            ->build();

        $this->assertSame('<div>Visible</div>', $engine->render('custom-prefix.sugar.php', ['show' => true]));
        $this->assertSame('', $engine->render('custom-prefix.sugar.php', ['show' => false]));
    }

    public function testWithTemplateLoader(): void
    {
        $tempDir = $this->createTempDir();
        $loader = new FileTemplateLoader([$tempDir]);

        $builder = new EngineBuilder();
        $result = $builder->withTemplateLoader($loader);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithTemplateLoaderUsesConfiguredFileSuffixes(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents($tempDir . '/custom.sugar.tpl', '<div>Custom</div>');

        $loader = new FileTemplateLoader(
            templatePaths: [$tempDir],
            suffixes: ['.sugar.tpl'],
        );

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->build();

        $this->assertStringContainsString('Custom', $engine->render('custom'));
    }

    public function testWithTemplateLoaderUsesConfiguredStringSuffixes(): void
    {
        $loader = new StringTemplateLoader(
            templates: ['custom.sugar.tpl' => '<div>Custom</div>'],
            suffixes: ['.sugar.tpl'],
        );

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->build();

        $this->assertStringContainsString('Custom', $engine->render('custom'));
    }

    public function testWithCache(): void
    {
        $tempDir = $this->createTempDir();
        $cacheDir = $this->createTempDir();
        $loader = new FileTemplateLoader([$tempDir]);
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
        $loader = new FileTemplateLoader([$tempDir]);
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
        $loader = new FileTemplateLoader([$tempDir]);

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withDebug(true);

        // Should return builder for fluent API
        $this->assertSame($builder, $result);

        $engine = $builder->build();
        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testWithPhpSyntaxValidationEnabledThrowsSyntaxException(): void
    {
        if (!class_exists(ParserFactory::class) || !class_exists(Error::class)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $loader = new StringTemplateLoader([
            'invalid-expression.sugar.php' => '<div><?= $value + ?></div>',
        ]);

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withDebug(true)
            ->withPhpSyntaxValidation(true)
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Invalid PHP expression');

        $engine->render('invalid-expression.sugar.php', ['value' => 1]);
    }

    public function testWithPhpSyntaxValidationEnabledButDebugDisabledUsesRuntimeParsePath(): void
    {
        if (!class_exists(ParserFactory::class) || !class_exists(Error::class)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $loader = new StringTemplateLoader([
            'invalid-expression-no-debug.sugar.php' => '<div><?= $value + ?></div>',
        ]);

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withDebug(false)
            ->withPhpSyntaxValidation(true)
            ->build();

        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Compiled template contains invalid PHP');

        $engine->render('invalid-expression-no-debug.sugar.php', ['value' => 1]);
    }

    public function testWithPhpSyntaxValidationDisabledByDefault(): void
    {
        if (!class_exists(ParserFactory::class) || !class_exists(Error::class)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $loader = new StringTemplateLoader([
            'invalid-expression-default.sugar.php' => '<div><?= $value + ?></div>',
        ]);

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->build();

        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Compiled template contains invalid PHP');

        $engine->render('invalid-expression-default.sugar.php', ['value' => 1]);
    }

    public function testWithPhpSyntaxValidationThrowsWhenParserMissing(): void
    {
        $builder = new EngineBuilder();

        if (class_exists(ParserFactory::class) && class_exists(Error::class)) {
            $this->assertSame($builder, $builder->withPhpSyntaxValidation(true));

            return;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nikic/php-parser is required to enable PHP syntax validation');

        $builder->withPhpSyntaxValidation(true);
    }

    public function testWithPhpSyntaxValidationDisabledDefersToGeneratedPhpValidation(): void
    {
        if (!class_exists(ParserFactory::class) || !class_exists(Error::class)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $loader = new StringTemplateLoader([
            'invalid-expression.sugar.php' => '<div><?= $value + ?></div>',
        ]);

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withPhpSyntaxValidation(false)
            ->build();

        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Compiled template contains invalid PHP');

        $engine->render('invalid-expression.sugar.php', ['value' => 1]);
    }

    public function testWithTemplateContext(): void
    {
        $tempDir = $this->createTempDir();
        $loader = new FileTemplateLoader([$tempDir]);
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

    public function testFragmentCacheExtensionCanBeRegistered(): void
    {
        $loader = new StringTemplateLoader();
        $fragmentCache = new ArraySimpleCache();

        $builder = new EngineBuilder();
        $result = $builder
            ->withTemplateLoader($loader)
            ->withExtension(new FragmentCacheExtension($fragmentCache));

        $this->assertSame($builder, $result);
        $this->assertInstanceOf(Engine::class, $builder->build());
    }

    public function testFragmentCacheExtensionCachesRenderedFragments(): void
    {
        $loader = new StringTemplateLoader([
            'cached.sugar.php' => '<div s:cache="\'users-list\'"><?= $value ?></div>',
        ]);

        $fragmentCache = new ArraySimpleCache();

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withExtension(new FragmentCacheExtension($fragmentCache))
            ->build();

        $this->assertSame('<div>First</div>', $engine->render('cached.sugar.php', ['value' => 'First']));
        $this->assertSame('<div>First</div>', $engine->render('cached.sugar.php', ['value' => 'Second']));
    }

    public function testFragmentCacheExtensionSupportsDefaultTtl(): void
    {
        $loader = new StringTemplateLoader([
            'cached-ttl.sugar.php' => '<div s:cache="\'users\'">Cached</div>',
        ]);

        $fragmentCache = new ArraySimpleCache();

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withExtension(new FragmentCacheExtension($fragmentCache, 600))
            ->build();

        $this->assertSame('<div>Cached</div>', $engine->render('cached-ttl.sugar.php'));
        $this->assertSame(600, $fragmentCache->ttls()['sugar_fragment:users']);
    }

    public function testFragmentCacheExtensionWithoutStoreStillRendersFreshContent(): void
    {
        $loader = new StringTemplateLoader([
            'cached.sugar.php' => '<div s:cache="\'users-list\'"><?= $value ?></div>',
        ]);

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withExtension(new FragmentCacheExtension(fragmentCache: null))
            ->build();

        $this->assertSame('<div>First</div>', $engine->render('cached.sugar.php', ['value' => 'First']));
        $this->assertSame('<div>Second</div>', $engine->render('cached.sugar.php', ['value' => 'Second']));
    }

    public function testComponentRendererServiceCannotBeOverriddenByLaterExtension(): void
    {
        $loader = new StringTemplateLoader([
            'page.sugar.php' => '<s-button>Click</s-button>',
            'components/s-button.sugar.php' => '<button class="btn"><?= $slot ?></button>',
        ]);

        $overridingExtension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                $context->runtimeService(ComponentExtension::SERVICE_RENDERER, new class {
                    /**
                     * @param array<string, mixed> $vars
                     * @param array<string, mixed> $slots
                     * @param array<string, mixed> $attributes
                     */
                    public function renderComponent(
                        string $name,
                        array $vars = [],
                        array $slots = [],
                        array $attributes = [],
                    ): string {
                        return 'OVERRIDDEN';
                    }
                });
            }
        };

        $engine = (new EngineBuilder())
            ->withTemplateLoader($loader)
            ->withExtension(new ComponentExtension())
            ->withExtension($overridingExtension)
            ->build();

        $output = $engine->render('page.sugar.php');

        $this->assertStringContainsString('<button class="btn">Click</button>', $output);
        $this->assertStringNotContainsString('OVERRIDDEN', $output);
    }

    public function testWithExceptionRenderer(): void
    {
        $loader = new StringTemplateLoader();
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
        $loader = new StringTemplateLoader();
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
        $loader = new StringTemplateLoader();
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
        $loader = new FileTemplateLoader([$tempDir]);
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
        $loader = new StringTemplateLoader();

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
        $loader = new StringTemplateLoader();

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
