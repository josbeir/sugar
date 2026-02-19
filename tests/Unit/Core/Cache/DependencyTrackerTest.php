<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\DependencyTracker;

/**
 * Tests for DependencyTracker helper
 */
final class DependencyTrackerTest extends TestCase
{
    public function testTracksDependencies(): void
    {
        $tracker = new DependencyTracker();

        $tracker->addDependency('/templates/layout.sugar.php');
        $tracker->addDependency('/templates/header.sugar.php');

        $metadata = $tracker->getMetadata('/templates/page.sugar.php', true);

        $this->assertSame(
            ['/templates/layout.sugar.php', '/templates/header.sugar.php'],
            $metadata->dependencies,
        );
        $this->assertSame('/templates/page.sugar.php', $metadata->sourcePath);
        $this->assertTrue($metadata->debug);
    }

    public function testTracksComponentPathsAsDependencies(): void
    {
        $tracker = new DependencyTracker();

        $tracker->addDependency('/components/s-button.sugar.php');
        $tracker->addDependency('/components/s-alert.sugar.php');

        $metadata = $tracker->getMetadata('/templates/page.sugar.php');

        $this->assertSame(
            ['/components/s-button.sugar.php', '/components/s-alert.sugar.php'],
            $metadata->dependencies,
        );
    }

    public function testIncludesSourceTimestamp(): void
    {
        $tracker = new DependencyTracker();

        // Create temporary file
        $tempFile = sys_get_temp_dir() . '/sugar_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php echo "test";');
        $expectedTime = filemtime($tempFile);

        $metadata = $tracker->getMetadata($tempFile);

        $this->assertSame($tempFile, $metadata->sourcePath);
        $this->assertSame($expectedTime, $metadata->sourceTimestamp);

        unlink($tempFile);
    }

    public function testIncludesCompiledTimestamp(): void
    {
        $tracker = new DependencyTracker();
        $before = time();

        $metadata = $tracker->getMetadata('/templates/page.sugar.php');

        $this->assertSame('/templates/page.sugar.php', $metadata->sourcePath);
        $this->assertGreaterThanOrEqual($before, $metadata->compiledTimestamp);
        $this->assertLessThanOrEqual(time(), $metadata->compiledTimestamp);
    }

    public function testDeduplicatesDependencies(): void
    {
        $tracker = new DependencyTracker();

        $tracker->addDependency('/templates/layout.sugar.php');
        $tracker->addDependency('/templates/layout.sugar.php');
        $tracker->addDependency('/templates/header.sugar.php');

        $metadata = $tracker->getMetadata('/templates/page.sugar.php');

        $this->assertSame(
            ['/templates/layout.sugar.php', '/templates/header.sugar.php'],
            $metadata->dependencies,
        );
    }

    public function testDeduplicatesComponentPathsInDependencies(): void
    {
        $tracker = new DependencyTracker();

        $tracker->addDependency('/components/s-button.sugar.php');
        $tracker->addDependency('/components/s-button.sugar.php');
        $tracker->addDependency('/components/s-alert.sugar.php');

        $metadata = $tracker->getMetadata('/templates/page.sugar.php');

        $this->assertSame(
            ['/components/s-button.sugar.php', '/components/s-alert.sugar.php'],
            $metadata->dependencies,
        );
    }

    public function testResetClearsDependencies(): void
    {
        $tracker = new DependencyTracker();

        $tracker->addDependency('/templates/layout.sugar.php');
        $tracker->addDependency('/components/s-button.sugar.php');

        $tracker->reset();

        $metadata = $tracker->getMetadata('/templates/page.sugar.php');

        $this->assertSame([], $metadata->dependencies);
    }
}
