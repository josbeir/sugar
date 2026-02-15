<?php
declare(strict_types=1);

namespace Sugar\Extension\Component;

use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Enum\PassPriority;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
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
    public const SERVICE_RENDERER = 'renderer.component';

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
        $context->compilerPass(
            pass: $this->resolveComponentExpansionPass($context),
            priority: PassPriority::POST_DIRECTIVE_COMPILATION,
        );

        $context->runtimeService(
            self::SERVICE_RENDERER,
            function (RegistrationContext $runtimeContext): ComponentRenderer {
                $compiler = $runtimeContext->getCompiler();
                $loader = $runtimeContext->getTemplateLoader();
                $cache = $runtimeContext->getTemplateCache();
                $config = $runtimeContext->getConfig();

                if (
                    !$compiler instanceof CompilerInterface
                    || !$loader instanceof TemplateLoaderInterface
                    || !$cache instanceof TemplateCacheInterface
                    || !$config instanceof SugarConfig
                ) {
                    throw new TemplateRuntimeException('Component renderer runtime dependencies are not initialized.');
                }

                $componentLoader = $this->getOrCreateComponentLoader($loader, $config);

                return new ComponentRenderer(
                    componentCompiler: new ComponentCompiler(
                        compiler: $compiler,
                        loader: $componentLoader,
                    ),
                    loader: $componentLoader,
                    cache: $cache,
                    tracker: $runtimeContext->getTracker(),
                    debug: $runtimeContext->isDebug(),
                    templateContext: $runtimeContext->getTemplateContext(),
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

        if (
            !$loader instanceof TemplateLoaderInterface
            || !$parser instanceof Parser
            || !$registry instanceof DirectiveRegistryInterface
            || !$config instanceof SugarConfig
        ) {
            throw new TemplateRuntimeException('Component extension registration dependencies are not initialized.');
        }

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
