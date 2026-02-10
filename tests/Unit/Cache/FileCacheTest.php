<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\CacheMetadata;
use Sugar\Cache\FileCache;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Util\Hash;

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

    public function testGetReturnsDefaultMetadataWhenMetaMissing(): void
    {
        $compiled = '<?php echo "Test";';
        $metadata = new CacheMetadata(sourceTimestamp: 123, compiledTimestamp: 456);

        $path = $this->cache->put('/templates/test-missing-meta.sugar.php', $compiled, $metadata);
        $metaPath = $path . '.meta';
        unlink($metaPath);

        $cached = $this->cache->get('/templates/test-missing-meta.sugar.php');

        $this->assertInstanceOf(CachedTemplate::class, $cached);
        $this->assertSame(0, $cached->metadata->compiledTimestamp);
        $this->assertSame(0, $cached->metadata->sourceTimestamp);
        $this->assertSame('', $cached->metadata->sourcePath);
        $this->assertSame([], $cached->metadata->dependencies);
    }

    public function testGetReturnsDefaultMetadataWhenMetaInvalid(): void
    {
        $compiled = '<?php echo "Test";';
        $metadata = new CacheMetadata(sourceTimestamp: 123, compiledTimestamp: 456);

        $path = $this->cache->put('/templates/test-invalid-meta.sugar.php', $compiled, $metadata);
        $metaPath = $path . '.meta';
        file_put_contents($metaPath, '{invalid json');

        $cached = $this->cache->get('/templates/test-invalid-meta.sugar.php');

        $this->assertInstanceOf(CachedTemplate::class, $cached);
        $this->assertSame(0, $cached->metadata->compiledTimestamp);
        $this->assertSame(0, $cached->metadata->sourceTimestamp);
        $this->assertSame('', $cached->metadata->sourcePath);
    }

    public function testGetReturnsNullWhenCacheNotFound(): void
    {
        $cached = $this->cache->get('/templates/nonexistent.sugar.php');

        $this->assertNotInstanceOf(CachedTemplate::class, $cached);
    }

    public function testDebugModeMismatchSkipsCache(): void
    {
        $compiled = '<?php echo "Debug";';
        $sourcePath = sys_get_temp_dir() . '/sugar_debug_only_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: (int)filemtime($sourcePath),
            debug: true,
        );

        $this->cache->put('/templates/debug-only.sugar.php', $compiled, $metadata);

        $cached = $this->cache->get('/templates/debug-only.sugar.php', debug: false);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        $cached = $this->cache->get('/templates/debug-only.sugar.php', debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }

    public function testPutSanitizesCacheFileName(): void
    {
        $compiled = '<?php echo "Test";';
        $metadata = new CacheMetadata();

        $path = $this->cache->put('/templates/user profile@.sugar.php', $compiled, $metadata);

        $basename = basename($path);
        $this->assertStringNotContainsString(' ', $basename);
        $this->assertStringNotContainsString('@', $basename);
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
        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );
        $this->cache->put($sourcePath, '<?php echo "cached v1";', $metadata);

        // Debug mode with fresh cache should return cached
        $cached = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        // Modify source file (newer timestamp)
        file_put_contents($sourcePath, '<?php echo "v2";');
        $this->bumpFileMtime($sourcePath);
        clearstatcache(); // Clear PHP's stat cache

        // Create new FileCache instance to simulate new request
        $cacheDir = sys_get_temp_dir() . '/sugar_newcache_' . uniqid();
        mkdir($cacheDir, 0755, true);
        $newCache = new FileCache($cacheDir);
        $newCache->put($sourcePath, '<?php echo "cached v1";', $metadata);

        // Debug mode with stale cache should return null (new instance detects change)
        $cached = $newCache->get($sourcePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
        // Cleanup new cache dir
        $this->removeTempDir($cacheDir);
    }

    public function testDebugModeChecksSourceTimestampWithBlocksKey(): void
    {
        $sourcePath = sys_get_temp_dir() . '/sugar_source_blocks_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );
        $cacheKey = $sourcePath . '::blocks:' . Hash::make('sidebar');

        $this->cache->put($cacheKey, '<?php echo "cached v1";', $metadata);
        $cached = $this->cache->get($cacheKey, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        file_put_contents($sourcePath, '<?php echo "v2";');
        $this->bumpFileMtime($sourcePath);
        clearstatcache();

        $cacheDir = sys_get_temp_dir() . '/sugar_newcache_' . uniqid();
        mkdir($cacheDir, 0755, true);
        $newCache = new FileCache($cacheDir);
        $newCache->put($cacheKey, '<?php echo "cached v1";', $metadata);

        $cached = $newCache->get($cacheKey, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
        $this->removeTempDir($cacheDir);
    }

    public function testDebugModeChecksAbsoluteSourcePathWithRelativeKey(): void
    {
        $sourcePath = sys_get_temp_dir() . '/sugar_source_abs_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        $cacheKey = 'pages/home.sugar.php';
        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );

        $sharedCacheDir = $this->createTempDir('sugar_cache_abs_');
        $cache1 = new FileCache($sharedCacheDir);
        $cache1->put($cacheKey, '<?php echo "cached v1";', $metadata);

        $cached = $cache1->get($cacheKey, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        file_put_contents($sourcePath, '<?php echo "v2";');
        $this->bumpFileMtime($sourcePath);
        clearstatcache();

        $cache2 = new FileCache($sharedCacheDir);
        $cached = $cache2->get($cacheKey, debug: true);
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

        // Cache page with layout dependency using shared cache directory
        $sharedCacheDir = $this->createTempDir('sugar_shared_cache_');
        $cache1 = new FileCache($sharedCacheDir);

        $metadata = new CacheMetadata(
            dependencies: [$layoutPath],
            sourcePath: $pagePath,
            sourceTimestamp: $pageTime,
            debug: true,
        );
        $cache1->put($pagePath, '<?php echo "cached page";', $metadata);

        // Debug mode with fresh cache should return cached
        $cached = $cache1->get($pagePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        // Modify layout (dependency)
        file_put_contents($layoutPath, '<?php echo "layout v2";');
        $this->bumpFileMtime($layoutPath);
        clearstatcache(); // Clear PHP's stat cache

        // Create new FileCache instance to simulate new request (shares same cache files)
        $cache2 = new FileCache($sharedCacheDir);

        // Debug mode should detect stale dependency (new instance with fresh stat cache)
        $cached = $cache2->get($pagePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($layoutPath);
        unlink($pagePath);
    }

    public function testDebugModeTreatsMissingDependencyFilesAsStale(): void
    {
        $sourcePath = sys_get_temp_dir() . '/sugar_missing_dep_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        $metadata = new CacheMetadata(
            dependencies: [$sourcePath . '.missing'],
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );

        $this->cache->put($sourcePath, '<?php echo "cached";', $metadata);

        $cached = $this->cache->get($sourcePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }

    public function testProductionModeSkipsTimestampChecks(): void
    {
        // Create temporary source file
        $sourcePath = sys_get_temp_dir() . '/sugar_prod_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        // Cache with timestamp
        $metadata = new CacheMetadata(sourceTimestamp: $sourceTime, debug: false);
        $this->cache->put($sourcePath, '<?php echo "cached";', $metadata);

        // Modify source file
        file_put_contents($sourcePath, '<?php echo "v2";');
        $this->bumpFileMtime($sourcePath);

        // Production mode (debug=false) should still return cached
        $cached = $this->cache->get($sourcePath, debug: false);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }

    public function testInvalidateHandlesCircularDependencies(): void
    {
        // Create circular reverse dependency: A←B←C←A
        // Layout is used by PageA, PageA is used by PageB, PageB is used by Layout
        // Without duplicate prevention, this would loop infinitely

        $metadataLayout = new CacheMetadata();
        $metadataPageA = new CacheMetadata(dependencies: ['templates/layout.sugar.php']);
        $metadataPageB = new CacheMetadata(dependencies: ['templates/page-a.sugar.php']);
        $metadataLayout2 = new CacheMetadata(dependencies: ['templates/page-b.sugar.php']);

        $this->cache->put('templates/layout.sugar.php', '<?php echo "Layout";', $metadataLayout);
        $this->cache->put('templates/page-a.sugar.php', '<?php echo "PageA";', $metadataPageA);
        $this->cache->put('templates/page-b.sugar.php', '<?php echo "PageB";', $metadataPageB);
        // Update layout to depend on page-b, creating circular dependency
        $this->cache->put('templates/layout.sugar.php', '<?php echo "Layout v2";', $metadataLayout2);

        // Invalidate layout - should process each template exactly once
        $invalidated = $this->cache->invalidate('templates/layout.sugar.php');

        // All three should be invalidated
        $this->assertCount(3, $invalidated);
        $this->assertContains('templates/layout.sugar.php', $invalidated);
        $this->assertContains('templates/page-a.sugar.php', $invalidated);
        $this->assertContains('templates/page-b.sugar.php', $invalidated);

        // Verify all are actually deleted
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('templates/layout.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('templates/page-a.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('templates/page-b.sugar.php'));
    }

    public function testInvalidateHandlesDiamondDependencies(): void
    {
        // Create diamond pattern in reverse dependency graph:
        // Base template is extended by both PageA and PageB
        // Both PageA and PageB are extended by FinalPage
        // When Base changes, FinalPage appears in queue twice but should only be processed once

        $metadataBase = new CacheMetadata();
        $metadataPageA = new CacheMetadata(dependencies: ['templates/base.sugar.php']);
        $metadataPageB = new CacheMetadata(dependencies: ['templates/base.sugar.php']);
        $metadataFinal = new CacheMetadata(dependencies: ['templates/page-a.sugar.php', 'templates/page-b.sugar.php']);

        $this->cache->put('templates/base.sugar.php', '<?php echo "Base";', $metadataBase);
        $this->cache->put('templates/page-a.sugar.php', '<?php echo "PageA";', $metadataPageA);
        $this->cache->put('templates/page-b.sugar.php', '<?php echo "PageB";', $metadataPageB);
        $this->cache->put('templates/final.sugar.php', '<?php echo "Final";', $metadataFinal);

        // Invalidate Base - Final should appear in queue twice but only be deleted once
        $invalidated = $this->cache->invalidate('templates/base.sugar.php');

        // All four should be invalidated exactly once
        $this->assertCount(4, $invalidated);
        $this->assertContains('templates/base.sugar.php', $invalidated);
        $this->assertContains('templates/page-a.sugar.php', $invalidated);
        $this->assertContains('templates/page-b.sugar.php', $invalidated);
        $this->assertContains('templates/final.sugar.php', $invalidated);

        // Verify Final appears exactly once in the result
        $finalCount = count(array_filter($invalidated, fn($key) => $key === 'templates/final.sugar.php'));
        $this->assertSame(1, $finalCount, 'Template Final should be invalidated exactly once');
    }

    public function testInvalidateHandlesSelfReference(): void
    {
        // Create self-referential dependency: A→A
        // This edge case should not cause infinite loop

        $metadata = new CacheMetadata(dependencies: ['templates/self.sugar.php']);
        $this->cache->put('templates/self.sugar.php', '<?php echo "Self";', $metadata);

        // Invalidate - should process exactly once
        $invalidated = $this->cache->invalidate('templates/self.sugar.php');

        // Should be invalidated exactly once
        $this->assertCount(1, $invalidated);
        $this->assertContains('templates/self.sugar.php', $invalidated);

        // Verify actually deleted
        $this->assertNotInstanceOf(CachedTemplate::class, $this->cache->get('templates/self.sugar.php'));
    }

    public function testInMemoryDependencyMapReducesFileIo(): void
    {
        // Cache multiple templates with dependencies
        $this->cache->put('templates/a.sugar.php', '<?php echo "A";', new CacheMetadata(['dep1']));
        $this->cache->put('templates/b.sugar.php', '<?php echo "B";', new CacheMetadata(['dep1']));
        $this->cache->put('templates/c.sugar.php', '<?php echo "C";', new CacheMetadata(['dep2']));

        // Dependencies should be tracked correctly
        $invalidated = $this->cache->invalidate('dep1');

        $this->assertCount(2, $invalidated);
        $this->assertContains('templates/a.sugar.php', $invalidated);
        $this->assertContains('templates/b.sugar.php', $invalidated);
        $this->assertNotContains('templates/c.sugar.php', $invalidated);
    }

    public function testDestructorPersistsDependencyMap(): void
    {
        $cacheDir = $this->createTempDir('sugar_cache_destruct_');
        $depMapPath = $cacheDir . '/dependencies.json';

        // Create cache and add template
        $cache = new FileCache($cacheDir);
        $cache->put('templates/test.sugar.php', '<?php echo "Test";', new CacheMetadata(['dep1']));

        // Explicitly destroy the cache object to trigger destructor
        unset($cache);

        // Verify dependency map was written by destructor
        $this->assertFileExists($depMapPath);

        $json = file_get_contents($depMapPath);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('dep1', $data);
        $this->assertIsArray($data['dep1']);
        $this->assertContains('templates/test.sugar.php', $data['dep1']);
    }

    public function testInvalidDependencyMapIsRebuilt(): void
    {
        $cacheDir = $this->createTempDir('sugar_cache_invalid_map_');
        $depMapPath = $cacheDir . '/dependencies.json';
        file_put_contents($depMapPath, '{invalid');

        $cache = new FileCache($cacheDir);
        $cache->put('templates/test.sugar.php', '<?php echo "Test";', new CacheMetadata(['dep1']));

        unset($cache);

        $json = file_get_contents($depMapPath);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('dep1', $data);
        $this->assertIsArray($data['dep1']);
        $this->assertContains('templates/test.sugar.php', $data['dep1']);
    }

    public function testFlushPersistsAndClearsDependencyMap(): void
    {
        $cacheDir = $this->createTempDir('sugar_cache_flush_');
        $cache = new FileCache($cacheDir);
        $depMapPath = $cacheDir . '/dependencies.json';

        // Add templates
        $cache->put('templates/a.sugar.php', '<?php echo "A";', new CacheMetadata(['dep1']));
        $cache->put('templates/b.sugar.php', '<?php echo "B";', new CacheMetadata(['dep2']));

        // Flush should persist dependency map before clearing
        $cache->flush();

        // Verify all caches cleared
        $this->assertNotInstanceOf(CachedTemplate::class, $cache->get('templates/a.sugar.php'));
        $this->assertNotInstanceOf(CachedTemplate::class, $cache->get('templates/b.sugar.php'));

        // Dependency map should be empty after flush (since all caches cleared)
        $this->assertFileExists($depMapPath);
        $json = file_get_contents($depMapPath);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function testBulkOperationsUseInMemoryCache(): void
    {
        $cacheDir = $this->createTempDir('sugar_cache_bulk_');
        $cache = new FileCache($cacheDir);

        // Cache 50 templates with dependencies
        $start = hrtime(true);
        for ($i = 0; $i < 50; $i++) {
            $cache->put(
                sprintf('templates/template%d.sugar.php', $i),
                sprintf("<?php echo 'Template %d';", $i),
                new CacheMetadata(['dep' . $i]),
            );
        }

        $duration = (hrtime(true) - $start) / 1e9;

        // Should complete very quickly (in-memory operations)
        // With old implementation: ~500ms (50 × 10ms I/O)
        // With new implementation: ~50ms (1 load + 1 save on destruct)
        $maxDuration = PHP_OS_FAMILY === 'Windows' ? 2.0 : 0.5;
        $this->assertLessThan($maxDuration, $duration, 'Bulk operations should be fast with in-memory cache');

        // Verify dependencies tracked correctly
        $invalidated = $cache->invalidate('dep25');
        $this->assertCount(1, $invalidated);
        $this->assertContains('templates/template25.sugar.php', $invalidated);
    }

    public function testInvalidationCascadeUsesInMemoryCache(): void
    {
        // Create cascade: layout -> page -> component
        $this->cache->put('templates/layout.sugar.php', '<?php echo "Layout";', new CacheMetadata());
        $this->cache->put('templates/page.sugar.php', '<?php echo "Page";', new CacheMetadata(['templates/layout.sugar.php']));
        $this->cache->put('templates/component.sugar.php', '<?php echo "Component";', new CacheMetadata(['templates/page.sugar.php']));

        // Invalidate layout should cascade to page and component
        $invalidated = $this->cache->invalidate('templates/layout.sugar.php');

        $this->assertCount(3, $invalidated);
        $this->assertContains('templates/layout.sugar.php', $invalidated);
        $this->assertContains('templates/page.sugar.php', $invalidated);
        $this->assertContains('templates/component.sugar.php', $invalidated);
    }

    public function testComponentDependenciesTrackedInMemory(): void
    {
        // Test that component dependencies (in addition to template dependencies) are tracked
        $this->cache->put(
            'templates/page.sugar.php',
            '<?php echo "Page";',
            new CacheMetadata(dependencies: ['templates/layout.sugar.php'], components: ['components/button.sugar.php']),
        );

        // Invalidating component should invalidate page
        $invalidated = $this->cache->invalidate('components/button.sugar.php');
        $this->assertContains('templates/page.sugar.php', $invalidated);

        // Invalidating layout should also invalidate page
        $this->cache->put(
            'templates/page2.sugar.php',
            '<?php echo "Page2";',
            new CacheMetadata(dependencies: ['templates/layout.sugar.php'], components: ['components/button.sugar.php']),
        );

        $invalidated = $this->cache->invalidate('templates/layout.sugar.php');
        $this->assertContains('templates/page2.sugar.php', $invalidated);
    }

    public function testDebugModeOptimizesStatCalls(): void
    {
        // Create temporary source file
        $sourcePath = sys_get_temp_dir() . '/sugar_stat_test_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "test";');
        $sourceTime = (int)filemtime($sourcePath);

        // Cache with correct timestamp
        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );
        $this->cache->put($sourcePath, '<?php echo "cached";', $metadata);

        // First debug check - should use filesystem
        $cached1 = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached1);

        // Subsequent debug checks should use cached mtime (request-level cache)
        $cached2 = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached2);

        $cached3 = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached3);

        // All checks should return same result efficiently
        $this->assertInstanceOf(CachedTemplate::class, $cached1);
        $this->assertInstanceOf(CachedTemplate::class, $cached2);
        $this->assertInstanceOf(CachedTemplate::class, $cached3);

        unlink($sourcePath);
    }

    public function testDebugModeWithMultipleDependenciesUsesMtimeCache(): void
    {
        // Create multiple temporary files
        $templatePath = sys_get_temp_dir() . '/sugar_template_' . uniqid() . '.php';
        $dep1Path = sys_get_temp_dir() . '/sugar_dep1_' . uniqid() . '.php';
        $dep2Path = sys_get_temp_dir() . '/sugar_dep2_' . uniqid() . '.php';
        $dep3Path = sys_get_temp_dir() . '/sugar_dep3_' . uniqid() . '.php';

        file_put_contents($templatePath, '<?php echo "template";');
        file_put_contents($dep1Path, '<?php echo "dep1";');
        file_put_contents($dep2Path, '<?php echo "dep2";');
        file_put_contents($dep3Path, '<?php echo "dep3";');

        $templateTime = (int)filemtime($templatePath);

        // Cache with multiple dependencies
        $metadata = new CacheMetadata(
            dependencies: [$dep1Path, $dep2Path, $dep3Path],
            sourcePath: $templatePath,
            sourceTimestamp: $templateTime,
            debug: true,
        );
        $this->cache->put($templatePath, '<?php echo "cached";', $metadata);

        // Multiple debug checks - should efficiently cache file mtimes
        for ($i = 0; $i < 5; $i++) {
            $cached = $this->cache->get($templatePath, debug: true);
            $this->assertInstanceOf(
                CachedTemplate::class,
                $cached,
                sprintf('Check #%d should use request-level mtime cache', $i),
            );
        }

        // Cleanup
        unlink($templatePath);
        unlink($dep1Path);
        unlink($dep2Path);
        unlink($dep3Path);
    }

    public function testDebugModeDetectsChangesAfterCacheCleared(): void
    {
        // Create temporary source file
        $sourcePath = sys_get_temp_dir() . '/sugar_change_detect_' . uniqid() . '.php';
        file_put_contents($sourcePath, '<?php echo "v1";');
        $sourceTime = (int)filemtime($sourcePath);

        // Cache with correct timestamp
        $metadata = new CacheMetadata(
            sourcePath: $sourcePath,
            sourceTimestamp: $sourceTime,
            debug: true,
        );
        $this->cache->put($sourcePath, '<?php echo "cached v1";', $metadata);

        // First check - should be fresh
        $cached = $this->cache->get($sourcePath, debug: true);
        $this->assertInstanceOf(CachedTemplate::class, $cached);

        // Modify source file
        file_put_contents($sourcePath, '<?php echo "v2";');
        $this->bumpFileMtime($sourcePath);
        clearstatcache(); // Simulate cache clearing

        // Create NEW FileCache instance (simulates new request)
        $cacheDir = $this->createTempDir('sugar_cache_new_');
        $newCache = new FileCache($cacheDir);
        $newCache->put($sourcePath, '<?php echo "cached v1";', $metadata);

        // New cache instance should detect change (has fresh static state)
        $cached = $newCache->get($sourcePath, debug: true);
        $this->assertNotInstanceOf(CachedTemplate::class, $cached);

        unlink($sourcePath);
    }
}
