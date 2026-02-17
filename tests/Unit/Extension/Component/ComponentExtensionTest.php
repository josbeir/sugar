<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component;

use Closure;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\Component\Runtime\ComponentRenderer;

/**
 * Tests for ComponentExtension registration behavior.
 */
final class ComponentExtensionTest extends TestCase
{
    public function testRegistersComponentExpansionPassAtComponentPriority(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(templates: [
            'components/s-card.sugar.php' => '<div><?= $slot ?></div>',
        ]);
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $context = new RegistrationContext(
            config: $config,
            templateLoader: $loader,
            templateCache: $this->createStub(TemplateCacheInterface::class),
            parser: $parser,
            directiveRegistry: $registry,
        );
        $extension = new ComponentExtension();
        $extension->register($context);

        $passes = $context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame(PassPriority::POST_DIRECTIVE_COMPILATION, $passes[0]['priority']);
    }

    public function testRegistersRendererServiceInitializerClosure(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(templates: [
            'components/s-card.sugar.php' => '<div><?= $slot ?></div>',
        ]);
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
            parser: $parser,
            directiveRegistry: $registry,
        );
        $extension = new ComponentExtension();
        $extension->register($context);

        $services = $context->getRuntimeServices();
        $this->assertArrayHasKey(ComponentExtension::SERVICE_RENDERER, $services);
        $this->assertInstanceOf(Closure::class, $services[ComponentExtension::SERVICE_RENDERER]);

        $runtimeContext = new RuntimeContext(
            compiler: $compiler,
            tracker: null,
        );

        $renderer = $services[ComponentExtension::SERVICE_RENDERER]($runtimeContext);
        $this->assertInstanceOf(ComponentRenderer::class, $renderer);
    }
}
