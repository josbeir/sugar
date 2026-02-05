<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CacheMetadata;

/**
 * Tests for CacheMetadata value object
 */
final class CacheMetadataTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $metadata = new CacheMetadata(
            dependencies: ['/templates/layout.sugar.php', '/templates/header.sugar.php'],
            components: ['/components/s-button.sugar.php'],
            sourceTimestamp: 1738765432,
            compiledTimestamp: 1738765433,
        );

        $this->assertSame(['/templates/layout.sugar.php', '/templates/header.sugar.php'], $metadata->dependencies);
        $this->assertSame(['/components/s-button.sugar.php'], $metadata->components);
        $this->assertSame(1738765432, $metadata->sourceTimestamp);
        $this->assertSame(1738765433, $metadata->compiledTimestamp);
    }

    public function testDefaultValues(): void
    {
        $metadata = new CacheMetadata();

        $this->assertSame([], $metadata->dependencies);
        $this->assertSame([], $metadata->components);
        $this->assertSame(0, $metadata->sourceTimestamp);
        $this->assertSame(0, $metadata->compiledTimestamp);
    }
}
