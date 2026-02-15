<?php
declare(strict_types=1);

namespace Sugar\Extension\Component;

use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Component\Compiler\ComponentTemplateCompiler;
use Sugar\Extension\Component\Loader\ComponentTemplateLoaderInterface;
use Sugar\Extension\Component\Loader\ResourceLocatorComponentTemplateLoader;
use Sugar\Extension\Component\Pass\ComponentExpansionPass;
use Sugar\Extension\Component\Pass\ComponentPassFactory;
use Sugar\Extension\Component\Pass\ComponentPassPriority;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Sugar\Extension\Component\Runtime\ComponentRuntimeServiceIds;

/**
 * Registers component expansion behavior and runtime services.
 *
 * This extension keeps component parsing in core while making component
 * expansion registration extension-driven.
 */
final class ComponentExtension implements ExtensionInterface
{
    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        $context->compilerPass(
            pass: $this->resolveComponentExpansionPass($context),
            priority: ComponentPassPriority::EXPANSION,
        );

        $context->runtimeService(
            ComponentRuntimeServiceIds::RENDERER,
            static function (RegistrationContext $runtimeContext): ComponentRenderer {
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

                $componentLoader = self::buildComponentLoader($loader, $config);

                return new ComponentRenderer(
                    componentCompiler: new ComponentTemplateCompiler(
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

        $componentLoader = self::buildComponentLoader($loader, $config);

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
     * Build component loader backed by core resource locator.
     */
    private static function buildComponentLoader(
        TemplateLoaderInterface $loader,
        SugarConfig $config,
    ): ComponentTemplateLoaderInterface {
        return ResourceLocatorComponentTemplateLoader::forTemplateLoader(
            templateLoader: $loader,
            config: $config,
            directories: ['components'],
        );
    }
}
