<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use stdClass;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

final class RuntimeEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeEnvironment::clear();
        parent::tearDown();
    }

    public function testRequireServiceThrowsWhenMissing(): void
    {
        RuntimeEnvironment::clear();

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Runtime service "stdClass" is not initialized.');

        RuntimeEnvironment::requireService(stdClass::class);
    }

    public function testSetAndClearNamedService(): void
    {
        $service = new stdClass();

        RuntimeEnvironment::setService(stdClass::class, $service);

        $this->assertSame($service, RuntimeEnvironment::requireService(stdClass::class));

        RuntimeEnvironment::clearService(stdClass::class);

        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService(stdClass::class);
    }

    public function testSetAndClearAllServices(): void
    {
        $serviceA = new stdClass();
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::set([
            stdClass::class => $serviceA,
            ArraySimpleCache::class => $fragmentCache,
        ]);

        $this->assertSame($serviceA, RuntimeEnvironment::requireService(stdClass::class));
        $this->assertSame($fragmentCache, RuntimeEnvironment::getService(ArraySimpleCache::class));

        RuntimeEnvironment::clear();

        $this->assertNull(RuntimeEnvironment::getService(ArraySimpleCache::class));
        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService(stdClass::class);
    }

    public function testHasAndGetService(): void
    {
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::setService('cache.fragment', $fragmentCache);
        $this->assertTrue(RuntimeEnvironment::hasService('cache.fragment'));
        $this->assertSame($fragmentCache, RuntimeEnvironment::getService('cache.fragment'));

        RuntimeEnvironment::clearService('cache.fragment');
        $this->assertFalse(RuntimeEnvironment::hasService('cache.fragment'));
        $this->assertNull(RuntimeEnvironment::getService('cache.fragment'));
    }
}
