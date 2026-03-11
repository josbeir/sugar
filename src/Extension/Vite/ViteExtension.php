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
 *
 * Named Vite namespaces can be registered to support multi-build setups. A namespace
 * is referenced in templates using the `@name/` prefix, e.g. `s:vite="'@theme/app.ts'"`.
 *
 * Example with a secondary namespace:
 *
 * ```php
 * new ViteExtension(
 *     assetBaseUrl: '/build/',
 *     manifestPath: ROOT . '/webroot/build/.vite/manifest.json',
 *     namespaces: [
 *         'theme' => new ViteConfig(
 *             assetBaseUrl: '/theme/build/',
 *             manifestPath: ROOT . '/plugins/Theme/webroot/build/.vite/manifest.json',
 *             devServerUrl: 'http://localhost:5174',
 *         ),
 *     ],
 * )
 * ```
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
     * @param array<string, \Sugar\Extension\Vite\ViteConfig> $namespaces Named namespace configurations keyed by namespace name
     */
    public function __construct(
        private string $assetBaseUrl,
        private string $mode = 'auto',
        private ?string $manifestPath = null,
        private string $devServerUrl = 'http://localhost:5173',
        private bool $injectClient = true,
        private ?string $defaultEntry = null,
        private array $namespaces = [],
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
                $default = new ViteConfig(
                    assetBaseUrl: $this->assetBaseUrl,
                    manifestPath: $this->manifestPath,
                    devServerUrl: $this->devServerUrl,
                    injectClient: $this->injectClient,
                    defaultEntry: $this->defaultEntry,
                );

                return new ViteAssetResolver(
                    mode: $this->mode,
                    debug: $debug,
                    default: $default,
                    namespaces: $this->namespaces,
                );
            },
        );
    }
}
