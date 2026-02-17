<?php
declare(strict_types=1);

namespace Sugar\Extension\Component;

use Sugar\Core\Enum\PassPriority;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Extension\Component\Compiler\ComponentCompiler;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;
use Sugar\Extension\Component\Loader\NamespacedComponentLoader;
use Sugar\Extension\Component\Pass\ComponentExpansionPass;
use Sugar\Extension\Component\Pass\ComponentPassFactory;
use Sugar\Extension\Component\Runtime\ComponentRenderer;

/**
 * Registers component expansion behavior and runtime services.
 *
 * This extension keeps component parsing in core while making component
 * expansion registration extension-driven.
 */
final class ComponentExtension implements ExtensionInterface
{
    /**
     * Runtime service id for the component renderer.
     */
    public const SERVICE_RENDERER = RuntimeEnvironment::RENDERER_SERVICE_ID;

    private ComponentLoaderInterface $componentLoader;

    /**
     * @param array<string> $componentDirectories Directories used to resolve component templates
     */
    public function __construct(
        private readonly array $componentDirectories = ['components'],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        $loader = $context->getTemplateLoader();
        $cache = $context->getTemplateCache();
        $config = $context->getConfig();
        $templateContext = $context->getTemplateContext();
        $debug = $context->isDebug();

        // Initialize component loader once
        $this->componentLoader = NamespacedComponentLoader::forTemplateLoader(
            templateLoader: $loader,
            config: $config,
            directories: $this->componentDirectories,
        );

        $context->compilerPass(
            pass: $this->resolveComponentExpansionPass($context),
            priority: PassPriority::POST_DIRECTIVE_COMPILATION,
        );

        $context->runtimeService(
            self::SERVICE_RENDERER,
            function (RuntimeContext $runtimeContext) use (
                $cache,
                $templateContext,
                $debug,
            ): ComponentRenderer {
                return new ComponentRenderer(
                    componentCompiler: new ComponentCompiler(
                        compiler: $runtimeContext->getCompiler(),
                        loader: $this->componentLoader,
                    ),
                    loader: $this->componentLoader,
                    cache: $cache,
                    tracker: $runtimeContext->getTracker(),
                    debug: $debug,
                    templateContext: $templateContext,
                );
            },
        );
    }

    /**
     * Resolve component expansion pass from registration context.
     */
    private function resolveComponentExpansionPass(RegistrationContext $context): ComponentExpansionPass
    {
        $loader = $context->getTemplateLoader();
        $parser = $context->getParser();
        $registry = $context->getDirectiveRegistry();
        $config = $context->getConfig();

        $passFactory = new ComponentPassFactory(
            templateLoader: $loader,
            componentLoader: $this->componentLoader,
            parser: $parser,
            registry: $registry,
            config: $config,
            customPasses: $context->getPasses(),
        );

        return $passFactory->createExpansionPass();
    }
}
