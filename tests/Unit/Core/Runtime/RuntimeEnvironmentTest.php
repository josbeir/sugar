<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

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
        $this->expectExceptionMessage('Runtime service "my.service" is not initialized.');

        RuntimeEnvironment::requireService('my.service');
    }

    public function testSetAndClearNamedService(): void
    {
        $service = new stdClass();

        RuntimeEnvironment::setService('test.service', $service);

        $this->assertSame($service, RuntimeEnvironment::requireService('test.service'));

        RuntimeEnvironment::clearService('test.service');

        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService('test.service');
    }

    public function testSetAndClearAllServices(): void
    {
        $serviceA = new stdClass();
        $fragmentCache = new ArraySimpleCache();

        RuntimeEnvironment::set([
            'service.a' => $serviceA,
            'cache.fragment' => $fragmentCache,
        ]);

        $this->assertSame($serviceA, RuntimeEnvironment::requireService('service.a'));
        $this->assertSame($fragmentCache, RuntimeEnvironment::getService('cache.fragment'));

        RuntimeEnvironment::clear();

        $this->assertNull(RuntimeEnvironment::getService('cache.fragment'));
        $this->expectException(TemplateRuntimeException::class);
        RuntimeEnvironment::requireService('service.a');
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
