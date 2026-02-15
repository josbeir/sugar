<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component;

use Closure;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\Component\Pass\ComponentPassPriority;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Sugar\Extension\Component\Runtime\ComponentRuntimeServiceIds;

/**
 * Tests for ComponentExtension registration behavior.
 */
final class ComponentExtensionTest extends TestCase
{
    public function testRegistersComponentExpansionPassAtComponentPriority(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(config: $config);
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $context = new RegistrationContext(
            config: $config,
            templateLoader: $loader,
            parser: $parser,
            directiveRegistry: $registry,
        );
        $extension = new ComponentExtension();
        $extension->register($context);

        $passes = $context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame(ComponentPassPriority::EXPANSION, $passes[0]['priority']);
    }

    public function testRegistersRendererServiceInitializerClosure(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(config: $config);
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();
        $compiler = new Compiler(
            parser: $parser,
            escaper: new Escaper(),
            registry: $registry,
            templateLoader: $loader,
            config: $config,
        );

        $context = new RegistrationContext(
            config: $config,
            templateLoader: $loader,
            templateCache: $this->createStub(TemplateCacheInterface::class),
            templateContext: null,
            debug: true,
            compiler: $compiler,
            parser: $parser,
            directiveRegistry: $registry,
        );
        $extension = new ComponentExtension();
        $extension->register($context);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(ComponentRuntimeServiceIds::RENDERER, $services);
        $this->assertInstanceOf(Closure::class, $services[ComponentRuntimeServiceIds::RENDERER]);

        $renderer = $services[ComponentRuntimeServiceIds::RENDERER]($context);
        $this->assertInstanceOf(ComponentRenderer::class, $renderer);
    }
}
