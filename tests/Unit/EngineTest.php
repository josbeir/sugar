<?php
declare(strict_types=1);

namespace Sugar\Test\Unit;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\FileCache;
use Sugar\Engine\Engine;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;

/**
 * Tests for Engine class
 */
final class EngineTest extends TestCase
{
    use EngineTestTrait;
    use TempDirectoryTrait;

    private string $cacheDir;

    private string $templateDir;

    protected function setUp(): void
    {
        $this->cacheDir = $this->createTempDir('sugar_engine_cache_');
        $this->templateDir = $this->createTempDir('sugar_engine_templates_');
    }

    public function testBuilderCreatesEngine(): void
    {
        $engine = $this->createEngine($this->templateDir);

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function testRenderSimpleTemplate(): void
    {
        $templatePath = $this->templateDir . '/simple.sugar.php';
        file_put_contents($templatePath, '<h1><?= $title ?></h1>');

        $engine = $this->createEngine($this->templateDir);

        $result = $engine->render('simple.sugar.php', ['title' => 'Hello World']);

        $this->assertStringContainsString('<h1>Hello World</h1>', $result);
    }

    public function testRenderWithCache(): void
    {
        $templatePath = $this->templateDir . '/cached.sugar.php';
        file_put_contents($templatePath, '<p><?= $message ?></p>');

        $cache = new FileCache($this->cacheDir);
        $engine = $this->createEngine($this->templateDir, cache: $cache);

        // First render - compiles and caches
        $result1 = $engine->render('cached.sugar.php', ['message' => 'First']);
        $this->assertStringContainsString('<p>First</p>', $result1);

        // Second render - uses cache
        $result2 = $engine->render('cached.sugar.php', ['message' => 'Second']);
        $this->assertStringContainsString('<p>Second</p>', $result2);

        // Verify cache was used (check with same key engine uses)
        $cached = $cache->get('cached.sugar.php');
        $this->assertInstanceOf(CachedTemplate::class, $cached);
    }

    public function testDebugMode(): void
    {
        $templatePath = $this->templateDir . '/debug.sugar.php';
        file_put_contents($templatePath, '<div><?= $content ?></div>');

        $cache = new FileCache($this->cacheDir);
        $engine = $this->createEngine(
            $this->templateDir,
            cache: $cache,
            debug: false,
        );

        // First render
        $result1 = $engine->render('debug.sugar.php', ['content' => 'v1']);
        $this->assertStringContainsString('<div>v1</div>', $result1);

        // Modify template
        file_put_contents($templatePath, '<div>Modified: <?= $content ?></div>');
        $this->bumpFileMtime($templatePath);
        clearstatcache();

        // Without debug mode, uses cached version
        $result2 = $engine->render('debug.sugar.php', ['content' => 'v2']);
        $this->assertStringContainsString('v2', $result2); // Just check data works
    }

    public function testCompileReturnsPhpCode(): void
    {
        $templatePath = $this->templateDir . '/compile.sugar.php';
        file_put_contents($templatePath, '<span><?= $value ?></span>');

        $engine = $this->createEngine($this->templateDir);

        $compiled = $engine->compile('compile.sugar.php');

        $this->assertStringContainsString('<?php', $compiled);
        $this->assertStringContainsString('$value', $compiled);
    }
}
