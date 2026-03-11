<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Vite;

use Closure;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Vite\Directive\ViteDirective;
use Sugar\Extension\Vite\Runtime\ViteAssetResolver;
use Sugar\Extension\Vite\ViteConfig;
use Sugar\Extension\Vite\ViteExtension;

/**
 * Tests ViteExtension registration behavior.
 */
final class ViteExtensionTest extends TestCase
{
    /**
     * Verify directive and runtime resolver service are registered.
     */
    public function testRegistersDirectiveAndRuntimeService(): void
    {
        $context = $this->createRegistrationContext();
        $extension = new ViteExtension(assetBaseUrl: '/build/', mode: 'dev', devServerUrl: 'http://localhost:5173');

        $extension->register($context);

        $directives = $context->getDirectives();
        $this->assertArrayHasKey('vite', $directives);
        $this->assertInstanceOf(ViteDirective::class, $directives['vite']);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(ViteAssetResolver::class, $services);
        $this->assertInstanceOf(Closure::class, $services[ViteAssetResolver::class]);
    }

    /**
     * Verify resolver service closure materializes to ViteAssetResolver.
     */
    public function testResolverServiceClosureMaterializesResolver(): void
    {
        $context = $this->createRegistrationContext();
        $extension = new ViteExtension(assetBaseUrl: '/build/', mode: 'dev');

        $extension->register($context);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(ViteAssetResolver::class, $services);
        $this->assertInstanceOf(Closure::class, $services[ViteAssetResolver::class]);

        $resolverFactory = $services[ViteAssetResolver::class];

        $runtimeContext = new RuntimeContext(
            compiler: $this->createStub(CompilerInterface::class),
            tracker: null,
        );

        $resolver = $resolverFactory($runtimeContext);
        $this->assertInstanceOf(ViteAssetResolver::class, $resolver);
    }

    /**
     * Verify namespace configs passed to ViteExtension result in a resolver with namespace support.
     */
    public function testNamespaceConfigsArePassedToResolver(): void
    {
        $context = $this->createRegistrationContext();
        $extension = new ViteExtension(
            assetBaseUrl: '/build/',
            mode: 'dev',
            namespaces: [
                'theme' => new ViteConfig(
                    assetBaseUrl: '/theme/build/',
                    devServerUrl: 'http://localhost:5174',
                ),
            ],
        );

        $extension->register($context);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(ViteAssetResolver::class, $services);
        $this->assertInstanceOf(Closure::class, $services[ViteAssetResolver::class]);

        $resolverFactory = $services[ViteAssetResolver::class];

        $runtimeContext = new RuntimeContext(
            compiler: $this->createStub(CompilerInterface::class),
            tracker: null,
        );

        $resolver = $resolverFactory($runtimeContext);
        $this->assertInstanceOf(ViteAssetResolver::class, $resolver);

        // Rendering a namespaced entry must use the namespace's dev server URL.
        $output = $resolver->render('@theme/resources/js/theme.ts');
        $this->assertStringContainsString('http://localhost:5174/resources/js/theme.ts', $output);
    }

    /**
     * Create a registration context fixture for extension tests.
     */
    private function createRegistrationContext(): RegistrationContext
    {
        $config = new SugarConfig();

        return new RegistrationContext(
            config: $config,
            templateLoader: new StringTemplateLoader(templates: []),
            templateCache: $this->createStub(TemplateCacheInterface::class),
            parser: new Parser($config),
            directiveRegistry: new DirectiveRegistry(),
            debug: true,
        );
    }
}
