<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\FragmentCache;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Extension\FragmentCache\Directive\FragmentCacheDirective;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;
use Sugar\Extension\FragmentCache\Runtime\FragmentCacheHelper;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

/**
 * Tests for FragmentCacheExtension registration behavior.
 */
final class FragmentCacheExtensionTest extends TestCase
{
    public function testRegistersDirectiveAndRuntimeServiceWhenStoreProvided(): void
    {
        $store = new ArraySimpleCache();
        $context = new RegistrationContext();

        $extension = new FragmentCacheExtension($store, 300);
        $extension->register($context);

        $directives = $context->getDirectives();
        $this->assertArrayHasKey('cache', $directives);
        $this->assertInstanceOf(FragmentCacheDirective::class, $directives['cache']);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(FragmentCacheHelper::SERVICE_ID, $services);
        $this->assertSame($store, $services[FragmentCacheHelper::SERVICE_ID]);
    }

    public function testRegistersDirectiveWithoutRuntimeServiceWhenStoreMissing(): void
    {
        $context = new RegistrationContext();

        $extension = new FragmentCacheExtension(fragmentCache: null);
        $extension->register($context);

        $directives = $context->getDirectives();
        $this->assertArrayHasKey('cache', $directives);
        $this->assertInstanceOf(FragmentCacheDirective::class, $directives['cache']);

        $this->assertSame([], $context->getRuntimeServices());
    }
}
