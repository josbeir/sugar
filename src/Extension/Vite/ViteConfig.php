<?php
declare(strict_types=1);

namespace Sugar\Extension\Vite;

/**
 * Holds Vite asset resolution configuration for a single namespace or the default context.
 *
 * Used internally by ViteAssetResolver to keep all config in one place regardless of whether
 * it describes the root (default) namespace or a named namespace (e.g. `@theme`).
 *
 * Example for defining a theme namespace config:
 *
 * ```php
 * new ViteConfig(
 *     assetBaseUrl: '/theme/build/',
 *     manifestPath: ROOT . '/plugins/Theme/webroot/build/.vite/manifest.json',
 *     devServerUrl: 'http://localhost:5174',
 *     defaultEntry: 'resources/js/theme.ts',
 * )
 * ```
 */
final readonly class ViteConfig
{
    /**
     * @param string $assetBaseUrl Public URL base for emitted production manifest assets (e.g. `/build/`)
     * @param string|null $manifestPath Absolute path to the Vite manifest file used in production mode
     * @param string|null $devServerUrl Vite dev server origin; when null the resolver falls back to the root config value
     * @param bool $injectClient Whether to inject `@vite/client` in development mode
     * @param string|null $defaultEntry Default entry path for this config. For the root/default config it is used when
     *   the directive spec is boolean true or null. For namespace configs it is resolved when the namespace is referenced
     *   without an explicit path (e.g. `'@theme'` or `'@theme/'`).
     */
    public function __construct(
        public string $assetBaseUrl,
        public ?string $manifestPath = null,
        public ?string $devServerUrl = null,
        public bool $injectClient = true,
        public ?string $defaultEntry = null,
    ) {
    }
}
