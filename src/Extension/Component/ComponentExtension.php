<?php
declare(strict_types=1);

namespace Sugar\Extension\Component;

use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Extension\Component\Loader\ComponentLoader;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;
use Sugar\Extension\Component\Pass\ComponentExpansionPass;
use Sugar\Extension\Component\Runtime\ComponentRenderer;

/**
 * Registers component expansion behavior and runtime services.
 *
 * This extension keeps component parsing in core while making component
 * expansion registration extension-driven.
 */
final class ComponentExtension implements ExtensionInterface
{
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
        $config = $context->getConfig();

        // Initialize component loader - it auto-detects namespaces from the template loader
        $this->componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: $config,
            componentDirectories: $this->componentDirectories,
        );

        $context->compilerPass(
            pass: new ComponentExpansionPass(
                loader: $this->componentLoader,
                registry: $context->getDirectiveRegistry(),
                config: $context->getConfig(),
            ),
            priority: PassPriority::POST_DIRECTIVE_COMPILATION,
        );

        $context->protectedRuntimeService(
            ComponentRenderer::class,
            function (RuntimeContext $runtimeContext): ComponentRenderer {
                return new ComponentRenderer(
                    loader: $this->componentLoader,
                );
            },
        );
    }
}
