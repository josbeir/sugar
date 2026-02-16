<?php
declare(strict_types=1);

namespace Sugar\Extension\Component;

use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Enum\PassPriority;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Extension\Component\Compiler\ComponentCompiler;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;
use Sugar\Extension\Component\Loader\ResourceLocatorLoader;
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

    private ?ComponentLoaderInterface $componentLoader = null;

    private ?TemplateLoaderInterface $componentTemplateLoader = null;

    private ?SugarConfig $componentLoaderConfig = null;

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

        $context->compilerPass(
            pass: $this->resolveComponentExpansionPass($context),
            priority: PassPriority::POST_DIRECTIVE_COMPILATION,
        );

        $context->runtimeService(
            self::SERVICE_RENDERER,
            function (RuntimeContext $runtimeContext) use (
                $loader,
                $cache,
                $config,
                $templateContext,
                $debug,
            ): ComponentRenderer {
                $componentLoader = $this->getOrCreateComponentLoader(
                    $loader,
                    $config,
                );

                return new ComponentRenderer(
                    componentCompiler: new ComponentCompiler(
                        compiler: $runtimeContext->getCompiler(),
                        loader: $componentLoader,
                    ),
                    loader: $componentLoader,
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
     *
     * @param \Sugar\Core\Extension\RegistrationContext $context Extension registration context
     */
    private function resolveComponentExpansionPass(RegistrationContext $context): ComponentExpansionPass
    {
        $loader = $context->getTemplateLoader();
        $parser = $context->getParser();
        $registry = $context->getDirectiveRegistry();
        $config = $context->getConfig();

        $componentLoader = $this->getOrCreateComponentLoader($loader, $config);

        $passFactory = new ComponentPassFactory(
            templateLoader: $loader,
            componentLoader: $componentLoader,
            parser: $parser,
            registry: $registry,
            config: $config,
            customPasses: $context->getPasses(),
        );

        return $passFactory->createExpansionPass();
    }

    /**
     * Build and cache a component loader for a loader/config pair.
     */
    private function getOrCreateComponentLoader(
        TemplateLoaderInterface $loader,
        SugarConfig $config,
    ): ComponentLoaderInterface {
        if (
            $this->componentLoader instanceof ComponentLoaderInterface
            && $this->componentTemplateLoader === $loader
            && $this->componentLoaderConfig === $config
        ) {
            return $this->componentLoader;
        }

        $this->componentLoader = ResourceLocatorLoader::forTemplateLoader(
            templateLoader: $loader,
            config: $config,
            directories: $this->componentDirectories,
        );

        $this->componentTemplateLoader = $loader;
        $this->componentLoaderConfig = $config;

        return $this->componentLoader;
    }
}
