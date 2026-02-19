<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\FragmentCache;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\FragmentCache\Directive\FragmentCacheDirective;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

/**
 * Tests for FragmentCacheExtension registration behavior.
 */
final class FragmentCacheExtensionTest extends TestCase
{
    public function testRegistersDirectiveAndRuntimeServiceWhenStoreProvided(): void
    {
        $store = new ArraySimpleCache();
        $context = $this->createRegistrationContext();

        $extension = new FragmentCacheExtension($store, 300);
        $extension->register($context);

        $directives = $context->getDirectives();
        $this->assertArrayHasKey('cache', $directives);
        $this->assertInstanceOf(FragmentCacheDirective::class, $directives['cache']);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(CacheInterface::class, $services);
        $this->assertSame($store, $services[CacheInterface::class]);
    }

    public function testRegistersDirectiveWithoutRuntimeServiceWhenStoreMissing(): void
    {
        $context = $this->createRegistrationContext();

        $extension = new FragmentCacheExtension(fragmentCache: null);
        $extension->register($context);

        $directives = $context->getDirectives();
        $this->assertArrayHasKey('cache', $directives);
        $this->assertInstanceOf(FragmentCacheDirective::class, $directives['cache']);

        $this->assertSame([], $context->getRuntimeServices());
    }

    private function createRegistrationContext(): RegistrationContext
    {
        $config = new SugarConfig();

        return new RegistrationContext(
            config: $config,
            templateLoader: new StringTemplateLoader(templates: []),
            templateCache: $this->createStub(TemplateCacheInterface::class),
            parser: new Parser($config),
            directiveRegistry: new DirectiveRegistry(),
        );
    }
}
