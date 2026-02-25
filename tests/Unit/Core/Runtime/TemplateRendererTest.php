<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\CacheKey;
use Sugar\Core\Cache\CacheMetadata;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Exception\CompilationException;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Runtime\BlockManager;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Runtime\TemplateRenderer;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

/**
 * Unit tests for TemplateRenderer.
 *
 * Tests the core runtime rendering service that handles extends, includes,
 * block inheritance, circular detection, dependency tracking, and template execution.
 */
final class TemplateRendererTest extends TestCase
{
    use CompilerTestTrait;
    use TempDirectoryTrait;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FileCache(
            cacheDir: $this->createTempDir('sugar_renderer_test_'),
        );
    }

    protected function tearDown(): void
    {
        RuntimeEnvironment::clearService(TemplateRenderer::class);
        $this->cleanupTempDirs();
        parent::tearDown();
    }

    /**
     * Create a TemplateRenderer with the given string templates.
     *
     * @param array<string, string> $templates Template map (path => source)
     * @param DependencyTracker|null $tracker Optional dependency tracker
     * @param object|null $context Optional template context for $this binding
     */
    protected function createRenderer(
        array $templates,
        ?DependencyTracker $tracker = null,
        ?object $context = null,
    ): TemplateRenderer {
        $this->setUpCompilerWithStringLoader($templates);

        $renderer = new TemplateRenderer(
            compiler: $this->compiler,
            loader: $this->templateLoader,
            cache: $this->cache,
            blockManager: new BlockManager(),
            tracker: $tracker,
            debug: true,
            templateContext: $context,
        );

        // Register in RuntimeEnvironment so compiled templates can find it
        RuntimeEnvironment::setService(
            TemplateRenderer::class,
            $renderer,
        );

        return $renderer;
    }

    public function testRenderIncludeRendersSubTemplate(): void
    {
        $renderer = $this->createRenderer([
            '@app/partial.sugar.php' => '<span>Included</span>',
        ]);

        $result = $renderer->renderInclude('@app/partial.sugar.php', []);

        $this->assertSame('<span>Included</span>', $result);
    }

    public function testRenderIncludePassesData(): void
    {
        $renderer = $this->createRenderer([
            '@app/greeting.sugar.php' => '<p>Hello <?= $name ?></p>',
        ]);

        $result = $renderer->renderInclude('@app/greeting.sugar.php', ['name' => 'World']);

        $this->assertSame('<p>Hello World</p>', $result);
    }

    public function testRenderIncludeStripsInternalVariables(): void
    {
        // renderInclude should strip __data, __e, __tpl from data
        $renderer = $this->createRenderer([
            '@app/test.sugar.php' => '<p>ok</p>',
        ]);

        // These internal variables should be silently stripped
        $result = $renderer->renderInclude('@app/test.sugar.php', [
            '__data' => 'should be removed',
            '__e' => 'should be removed',
            '__tpl' => 'should be removed',
            'name' => 'kept',
        ]);

        $this->assertSame('<p>ok</p>', $result);
    }

    public function testRenderExtendsSimpleInheritance(): void
    {
        $renderer = $this->createRenderer([
            '@app/child.sugar.php' => '<s-template s:extends="@app/layout.sugar.php"><main s:block="content">Child Content</main></s-template>',
            '@app/layout.sugar.php' => '<html><body><main s:block="content">Default</main></body></html>',
        ]);

        $result = $renderer->renderExtends('@app/layout.sugar.php', []);

        // The layout should render with the default block content
        // (no child blocks defined yet since we're calling renderExtends directly)
        $this->assertStringContainsString('<html>', $result);
        $this->assertStringContainsString('Default', $result);
    }

    public function testRenderExtendsCircularDetection(): void
    {
        $renderer = $this->createRenderer([
            '@app/a.sugar.php' => '<s-template s:extends="@app/b.sugar.php"><div s:block="content">A</div></s-template>',
            '@app/b.sugar.php' => '<s-template s:extends="@app/a.sugar.php"><div s:block="content">B</div></s-template>',
        ]);

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Circular template inheritance detected');

        $renderer->renderExtends('@app/a.sugar.php', []);
    }

    public function testRenderExtendsPushesAndPopsBlockLevel(): void
    {
        $renderer = $this->createRenderer([
            '@app/layout.sugar.php' => '<html><main s:block="content">Default</main></html>',
        ]);

        $blockManager = $renderer->getBlockManager();
        $this->assertFalse($blockManager->hasLevels());

        // After renderExtends completes, level should be popped
        $renderer->renderExtends('@app/layout.sugar.php', []);
        $this->assertFalse($blockManager->hasLevels());
    }

    public function testRenderTemplateCachesCompiledOutput(): void
    {
        $renderer = $this->createRenderer([
            '@app/cached.sugar.php' => '<p>Cached</p>',
        ]);

        $result1 = $renderer->renderTemplate('@app/cached.sugar.php', []);
        $result2 = $renderer->renderTemplate('@app/cached.sugar.php', []);

        $this->assertSame($result1, $result2);
        $this->assertSame('<p>Cached</p>', $result1);
    }

    public function testCompileTemplateReturnsPhpCode(): void
    {
        $renderer = $this->createRenderer([
            '@app/simple.sugar.php' => '<p>Hello</p>',
        ]);

        $code = $renderer->compileTemplate('@app/simple.sugar.php');

        $this->assertStringContainsString('return', $code);
        $this->assertStringContainsString('Hello', $code);
    }

    public function testDependencyTrackingPropagates(): void
    {
        $tracker = new DependencyTracker();

        $renderer = $this->createRenderer(
            [
                '@app/main.sugar.php' => '<p>Main</p>',
            ],
            tracker: $tracker,
        );

        $renderer->renderInclude('@app/main.sugar.php', []);

        $metadata = $tracker->getMetadata('test.sugar.php', true);
        // The rendered template should be tracked as a dependency
        $this->assertNotEmpty($metadata->dependencies);
    }

    public function testGetBlockManagerReturnsSameInstance(): void
    {
        $renderer = $this->createRenderer([]);
        $bm1 = $renderer->getBlockManager();
        $bm2 = $renderer->getBlockManager();

        $this->assertSame($bm1, $bm2);
    }

    public function testHasDefinedBlockDelegatesToBlockManager(): void
    {
        $renderer = $this->createRenderer([]);

        $this->assertFalse($renderer->hasDefinedBlock('sidebar'));

        $renderer->getBlockManager()->defineBlock('sidebar', fn(array $data): string => 'Sidebar');

        $this->assertTrue($renderer->hasDefinedBlock('sidebar'));
    }

    public function testTemplateContextBindsThis(): void
    {
        $context = new class {
            public function greet(): string
            {
                return 'Hello from context';
            }
        };

        $renderer = $this->createRenderer(
            ['@app/ctx.sugar.php' => '<p><?= $this->greet() ?></p>'],
            context: $context,
        );

        $result = $renderer->renderInclude('@app/ctx.sugar.php', []);
        $this->assertSame('<p>Hello from context</p>', $result);
    }

    public function testRenderIncludeMultipleTemplates(): void
    {
        $renderer = $this->createRenderer([
            '@app/header.sugar.php' => '<header>Header</header>',
            '@app/footer.sugar.php' => '<footer>Footer</footer>',
        ]);

        $header = $renderer->renderInclude('@app/header.sugar.php', []);
        $footer = $renderer->renderInclude('@app/footer.sugar.php', []);

        $this->assertSame('<header>Header</header>', $header);
        $this->assertSame('<footer>Footer</footer>', $footer);
    }

    public function testRenderIncludeReturnsEmptyStringWhenCachedTemplateDoesNotReturnClosure(): void
    {
        $renderer = $this->createRenderer([
            '@app/cached-non-closure.sugar.php' => '<div>ignored</div>',
        ]);

        $resolved = $this->templateLoader->resolve('@app/cached-non-closure.sugar.php');
        $cacheKey = CacheKey::fromTemplate($resolved);
        $this->cache->put(
            $cacheKey,
            '<?php return 123;',
            new CacheMetadata(debug: true),
        );

        $result = $renderer->renderInclude('@app/cached-non-closure.sugar.php', []);

        $this->assertSame('', $result);
    }

    public function testRenderIncludeNormalizesNonStringClosureResultToDisplayString(): void
    {
        $renderer = $this->createRenderer([
            '@app/cached-non-string.sugar.php' => '<div>ignored</div>',
        ]);

        $resolved = $this->templateLoader->resolve('@app/cached-non-string.sugar.php');
        $cacheKey = CacheKey::fromTemplate($resolved);
        $this->cache->put(
            $cacheKey,
            '<?php return static function(array $__data): array { return ["x" => 1]; };',
            new CacheMetadata(debug: true),
        );

        $result = $renderer->renderInclude('@app/cached-non-string.sugar.php', []);

        $this->assertSame('', $result);
    }

    public function testRenderIncludeWrapsCompiledTemplateParseErrors(): void
    {
        $renderer = $this->createRenderer([
            '@app/cached-parse-error.sugar.php' => '<div>ignored</div>',
        ]);

        $resolved = $this->templateLoader->resolve('@app/cached-parse-error.sugar.php');
        $cacheKey = CacheKey::fromTemplate($resolved);
        $this->cache->put(
            $cacheKey,
            '<?php return static function(array $__data): string {',
            new CacheMetadata(debug: true),
        );

        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Compiled template contains invalid PHP');

        $renderer->renderInclude('@app/cached-parse-error.sugar.php', []);
    }
}
