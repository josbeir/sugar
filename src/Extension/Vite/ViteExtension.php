<?php
declare(strict_types=1);

namespace Sugar\Extension\Vite;

use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Extension\RuntimeContext;
use Sugar\Extension\Vite\Directive\ViteDirective;
use Sugar\Extension\Vite\Runtime\ViteAssetResolver;

/**
 * Registers Vite asset rendering support for templates.
 *
 * This extension provides the `s:vite` directive and a runtime resolver service
 * that emits development or production asset tags.
 */
final readonly class ViteExtension implements ExtensionInterface
{
    /**
     * @param string $assetBaseUrl Public URL base for emitted manifest assets (for example `/build/`)
     * @param string $mode Resolver mode: `auto`, `dev`, or `prod`
     * @param string|null $manifestPath Absolute path to Vite manifest file for production mode
     * @param string $devServerUrl Vite dev server origin (e.g. `http://localhost:5173`)
     * @param bool $injectClient Whether to inject `@vite/client` in development mode
     * @param string|null $defaultEntry Optional default entry used when `s:vite` is boolean
     */
    public function __construct(
        private string $assetBaseUrl,
        private string $mode = 'auto',
        private ?string $manifestPath = null,
        private string $devServerUrl = 'http://localhost:5173',
        private bool $injectClient = true,
        private ?string $defaultEntry = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        $context->directive('vite', new ViteDirective());
        $debug = $context->isDebug();

        $context->protectedRuntimeService(
            ViteAssetResolver::class,
            function (RuntimeContext $runtimeContext) use ($debug): ViteAssetResolver {
                return new ViteAssetResolver(
                    mode: $this->mode,
                    debug: $debug,
                    manifestPath: $this->manifestPath,
                    assetBaseUrl: $this->assetBaseUrl,
                    devServerUrl: $this->devServerUrl,
                    injectClient: $this->injectClient,
                    defaultEntry: $this->defaultEntry,
                );
            },
        );
    }
}
