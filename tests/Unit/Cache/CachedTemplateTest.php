<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Sugar\Cache\CachedTemplate;
use Sugar\Cache\CacheMetadata;

/**
 * Tests for CachedTemplate value object
 */
final class CachedTemplateTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $metadata = new CacheMetadata(
            dependencies: ['/templates/layout.sugar.php'],
        );

        $cached = new CachedTemplate(
            path: '/cache/ab/home-abc123.php',
            metadata: $metadata,
        );

        $this->assertSame('/cache/ab/home-abc123.php', $cached->path);
        $this->assertSame($metadata, $cached->metadata);
    }
}
