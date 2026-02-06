<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\CacheMetadata;
use Sugar\Cache\FileCache;
use Sugar\Tests\TempDirectoryTrait;

/**
 * Tests for FileCache implementation
 */
final class FileCacheTest extends TestCase
{
    use TempDirectoryTrait;

    private FileCache $cache;

    protected function setUp(): void
    {
        $cacheDir = $this->createTempDir('sugar_cache_test_');
        $this->cache = new FileCache($cacheDir);
    }

    public function testPutCreatesCache(): void
    {
        $compiled = '<?php echo "Hello World";';
        $metadata = new CacheMetadata();

        $path = $this->cache->put('/templates/home.sugar.php', $compiled, $metadata);

        $this->assertFileExists($path);
        $this->assertStringContainsString('home-', basename($path));
    }

    public function testGetReturnsCachedTemplate(): void
    {
        $compiled = '<?php echo "Test";';
        $metadata = new CacheMetadata();

        $this->cache->put('/templates/test.sugar.php', $compiled, $metadata);
        $cached = $this->cache->get('/templates/test.sugar.php');

        $this->assertInstanceOf(CachedTemplate::class, $cached);
        $this->assertFileExists($cached->path);
    }

    public function testGetReturnsNullWhenCacheNotFound(): void
    {
        $cached = $this->cache->get('/templates/nonexistent.sugar.php');

        $this->assertNotInstanceOf(CachedTemplate::class, $cached);
    }

    public function testDeleteRemovesCache(): void
    {
        $compiled = '<?php echo "Delete me";';
        $this->cache->put('/templates/delete.sugar.php', $compiled, new CacheMetadata());

        $result = $this->cache->delete('/templates/delete.sugar.php');

        $this->assertTrue($result);
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/delete.sugar.php'));
    }

    public function testFlushClearsAllCache(): void
    {
        $this->cache->put('/templates/a.sugar.php', '<?php echo "A";', new CacheMetadata());
        $this->cache->put('/templates/b.sugar.php', '<?php echo "B";', new CacheMetadata());

        $this->cache->flush();

        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/a.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/b.sugar.php'));
    }

    public function testInvalidateCascadesToDependents(): void
    {
        // Layout template
        $this->cache->put('/templates/layout.sugar.php', '<?php echo "Layout";', new CacheMetadata());

        // Page A depends on layout
        $metadataA = new CacheMetadata(dependencies: ['/templates/layout.sugar.php']);
        $this->cache->put('/templates/page-a.sugar.php', '<?php echo "Page A";', $metadataA);

        // Page B depends on layout
        $metadataB = new CacheMetadata(dependencies: ['/templates/layout.sugar.php']);
        $this->cache->put('/templates/page-b.sugar.php', '<?php echo "Page B";', $metadataB);

        // Page C has no dependencies
        $this->cache->put('/templates/page-c.sugar.php', '<?php echo "Page C";', new CacheMetadata());

        // Invalidate layout - should cascade to A and B
        $invalidated = $this->cache->invalidate('/templates/layout.sugar.php');

        $this->assertContains('/templates/layout.sugar.php', $invalidated);
        $this->assertContains('/templates/page-a.sugar.php', $invalidated);
        $this->assertContains('/templates/page-b.sugar.php', $invalidated);
        $this->assertNotContains('/templates/page-c.sugar.php', $invalidated);

        // Verify caches are actually deleted
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/layout.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/page-a.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('/templates/page-b.sugar.php'));
        $this->assertInstanceOf(CachedTemplate::class, $this->cache->get('/templates/page-c.sugar.php'));
    }

    public function testInvalidateHandlesDeepDependencyChains(): void
    {
        // Layout (root)
        $this->cache->put('/templates/layout.sugar.php', '<?php echo "Layout";', new CacheMetadata());

        // Section depends on layout
        $metadataSection = new CacheMetadata(dependencies: ['/templates/layout.sugar.php']);
        $this->cache->put('/templates/section.sugar.php', '<?php echo "Section";', $metadataSection);

        // Page depends on section
        $metadataPage = new CacheMetadata(dependencies: ['/templates/section.sugar.php']);
        $this->cache->put('/templates/page.sugar.php', '<?php echo "Page";', $metadataPage);

        // Invalidate layout - should cascade through section to page
        $invalidated = $this->cache->invalidate('/templates/layout.sugar.php');

        $this->assertContains('/templates/layout.sugar.php', $invalidated);
        $this->assertContains('/templates/section.sugar.php', $invalidated);
        $this->assertContains('/templates/page.sugar.php', $invalidated);
    }

    public function testInvalidateWithComponents(): void
    {
        // Component
        $this->cache->put('/components/s-button.sugar.php', '<?php echo "Button";', new CacheMetadata());

        // Page uses component
        $metadata = new CacheMetadata(components: ['/components/s-button.sugar.php']);
        $this->cache->put('/templates/page.sugar.php', '<?php echo "Page";', $metadata);

        // Invalidate component - should cascade to page
        $invalidated = $this->cache->invalidate('/components/s-button.sugar.php');

        $this->assertContains('/components/s-button.sugar.php', $invalidated);
        $this->assertContains('/templates/page.sugar.php', $invalidated);
    }

    public function testDebugModeChecksSourceTimestamp(): void
    {
        // Create temporary source file
        $sourcePath = sys_get_temp_dir() . '/sugar_source_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        // Cache with correct timestamp
        $metadata = new CacheMetadata(sourceTimestamp: $sourceTime);
        $this->cache->put($sourcePath, '<?php echo "cached v1";', $metadata);

        // Debug mode with fresh cache should return cached
        $cached = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        // Modify source file (newer timestamp)
        sleep(1);
        file_put_contents($sourcePath, '<?php echo "v2";');
        touch($sourcePath); // Update mtime
        clearstatcache(); // Clear PHP's stat cache

        // Debug mode with stale cache should return null
        $cached = $this->cache->get($sourcePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }

    public function testDebugModeChecksDependencyTimestamps(): void
    {
        // Create temporary files
        $layoutPath = sys_get_temp_dir() . '/sugar_layout_' . uniqid() . '.php';
        $pagePath = sys_get_temp_dir() . '/sugar_page_' . uniqid() . '.php';
        file_put_contents($layoutPath, '<?php echo "layout v1";');
        file_put_contents($pagePath, '<?php echo "page v1";');
        $pageTime = (int)filemtime($pagePath);

        // Cache page with layout dependency
        $metadata = new CacheMetadata(
            dependencies: [$layoutPath],
            sourceTimestamp: $pageTime,
        );
        $this->cache->put($pagePath, '<?php echo "cached page";', $metadata);

        // Debug mode with fresh cache should return cached
        $cached = $this->cache->get($pagePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        // Modify layout (dependency)
        sleep(1);
        file_put_contents($layoutPath, '<?php echo "layout v2";');
        touch($layoutPath);
        clearstatcache(); // Clear PHP's stat cache

        // Debug mode should detect stale dependency
        $cached = $this->cache->get($pagePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($layoutPath);
        unlink($pagePath);
    }

    public function testProductionModeSkipsTimestampChecks(): void
    {
        // Create temporary source file
        $sourcePath = sys_get_temp_dir() . '/sugar_prod_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        // Cache with timestamp
        $metadata = new CacheMetadata(sourceTimestamp: $sourceTime);
        $this->cache->put($sourcePath, '<?php echo "cached";', $metadata);

        // Modify source file
        sleep(1);
        file_put_contents($sourcePath, '<?php echo "v2";');
        touch($sourcePath);

        // Production mode (debug=false) should still return cached
        $cached = $this->cache->get($sourcePath, debug: false);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }
}
