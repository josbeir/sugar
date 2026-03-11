<?php
declare(strict_types=1);

namespace Sugar\Extension\Vite\Runtime;

use Sugar\Core\Escape\Escaper;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Extension\Vite\ViteConfig;

/**
 * Resolves and renders Vite asset tags at runtime.
 *
 * In development mode this emits `@vite/client` and entry module scripts.
 * In production mode this resolves entries from `manifest.json` and emits
 * stylesheet and module script tags.
 *
 * Named namespace configs (e.g. `@theme`) allow multi-build setups where each
 * namespace has its own manifest, base URL, and optional dev server.
 */
final class ViteAssetResolver
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $manifests = [];

    /**
     * @var array<string, true>
     */
    private array $emittedTags = [];

    /**
     * @var array<string, true>
     */
    private array $injectedClients = [];

    /**
     * @param string $mode Resolver mode: `auto`, `dev`, or `prod`
     * @param bool $debug Whether engine debug mode is enabled
     * @param \Sugar\Extension\Vite\ViteConfig $default Configuration for the default (unnamed) namespace
     * @param array<string, \Sugar\Extension\Vite\ViteConfig> $namespaces Named namespace configurations keyed by namespace name
     */
    public function __construct(
        private readonly string $mode,
        private readonly bool $debug,
        private readonly ViteConfig $default,
        private readonly array $namespaces = [],
    ) {
        if (trim($this->default->assetBaseUrl) === '') {
            throw new TemplateRuntimeException('Vite assetBaseUrl must be configured and non-empty.');
        }
    }

    /**
     * Render Vite tags from a directive specification.
     *
     * Supported specs:
     * - string entry path
     * - list of entry paths
     * - array with `entry` or `entries`
     * - boolean true/null to use configured default entry
     *
     * @param mixed $spec Entry specification
     * @return string Rendered HTML tags
     */
    public function render(mixed $spec): string
    {
        $entries = $this->normalizeEntries($spec);
        if ($entries === []) {
            return '';
        }

        if ($this->isDevMode()) {
            return $this->renderDevelopmentTags($entries);
        }

        return $this->renderProductionTags($entries);
    }

    /**
     * Determine whether resolver is in development mode.
     */
    private function isDevMode(): bool
    {
        return match (strtolower(trim($this->mode))) {
            'dev' => true,
            'prod' => false,
            default => $this->debug,
        };
    }

    /**
     * Parse a `@namespace/path` entry into its namespace name and bare path.
     *
     * Returns `[null, $entry]` for entries without a namespace prefix.
     *
     * @return array{0: string|null, 1: string}
     */
    private function parseEntry(string $entry): array
    {
        if (!str_starts_with($entry, '@')) {
            return [null, $entry];
        }

        $slashPos = strpos($entry, '/');
        if ($slashPos === false) {
            return [null, $entry];
        }

        return [substr($entry, 1, $slashPos - 1), substr($entry, $slashPos + 1)];
    }

    /**
     * Resolve the ViteConfig for a given namespace name.
     *
     * Falls back to the default config when namespace is null.
     *
     * @param string|null $namespace Namespace name or null for the default
     * @throws \Sugar\Core\Exception\TemplateRuntimeException When the namespace is not registered
     */
    private function resolveConfig(?string $namespace): ViteConfig
    {
        if ($namespace === null) {
            return $this->default;
        }

        if (!isset($this->namespaces[$namespace])) {
            throw new TemplateRuntimeException(sprintf(
                'Vite namespace "@%s" is not registered. Registered namespaces: %s.',
                $namespace,
                $this->namespaces === [] ? '(none)' : implode(', ', array_map(
                    static fn(string $n): string => '@' . $n,
                    array_keys($this->namespaces),
                )),
            ));
        }

        return $this->namespaces[$namespace];
    }

    /**
     * Resolve the effective dev server URL for a config, falling back to the default.
     */
    private function resolveDevServerUrl(ViteConfig $config): string
    {
        return $config->devServerUrl ?? $this->default->devServerUrl ?? 'http://localhost:5173';
    }

    /**
     * Normalize directive specification into a list of `[namespace, path]` tuples.
     *
     * @param mixed $spec Entry specification
     * @return array<array{0: string|null, 1: string}>
     */
    private function normalizeEntries(mixed $spec): array
    {
        $raw = $this->normalizeRawEntries($spec);
        $result = [];

        foreach ($raw as $entry) {
            [$namespace, $path] = $this->parseEntry($entry);

            if ($path !== '') {
                $result[] = [$namespace, $path];
            }
        }

        return $result;
    }

    /**
     * Normalize directive specification into a flat list of raw entry strings.
     *
     * @param mixed $spec Entry specification
     * @return array<string>
     */
    private function normalizeRawEntries(mixed $spec): array
    {
        if ($spec === null || $spec === true) {
            return $this->default->defaultEntry !== null ? [$this->default->defaultEntry] : [];
        }

        if (is_string($spec)) {
            $entry = trim($spec);

            return $entry === '' ? [] : [$entry];
        }

        if (is_array($spec)) {
            if (array_key_exists('entry', $spec) && is_string($spec['entry'])) {
                $entry = trim($spec['entry']);

                return $entry === '' ? [] : [$entry];
            }

            if (array_key_exists('entries', $spec) && is_array($spec['entries'])) {
                return $this->collectStringEntries($spec['entries']);
            }

            return $this->collectStringEntries($spec);
        }

        throw new TemplateRuntimeException('s:vite expects a string, list, or options array expression.');
    }

    /**
     * Collect non-empty string entries from a list.
     *
     * @param array<mixed> $items
     * @return array<string>
     */
    private function collectStringEntries(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $normalized = trim($item);
            if ($normalized !== '') {
                $entries[] = $normalized;
            }
        }

        return $entries;
    }

    /**
     * Render development mode tags for entries.
     *
     * @param array<array{0: string|null, 1: string}> $entries Parsed namespace+path tuples
     * @return string Rendered HTML tags
     */
    private function renderDevelopmentTags(array $entries): string
    {
        $tags = [];

        foreach ($entries as [$namespace, $path]) {
            $config = $this->resolveConfig($namespace);
            $devServerUrl = $this->resolveDevServerUrl($config);
            $configKey = $namespace ?? '';

            if ($config->injectClient && !isset($this->injectedClients[$configKey])) {
                $clientUrl = $this->normalizeDevUrl('@vite/client', $devServerUrl);
                $tag = '<script type="module" src="' . Escaper::attr($clientUrl) . '"></script>';
                $deduped = $this->emitTag($tag);
                if ($deduped !== null) {
                    $tags[] = $deduped;
                    $this->injectedClients[$configKey] = true;
                }
            }

            $entryUrl = $this->normalizeDevUrl($path, $devServerUrl);
            $tag = '<script type="module" src="' . Escaper::attr($entryUrl) . '"></script>';
            $deduped = $this->emitTag($tag);
            if ($deduped !== null) {
                $tags[] = $deduped;
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Render production mode tags for entries.
     *
     * @param array<array{0: string|null, 1: string}> $entries Parsed namespace+path tuples
     * @return string Rendered HTML tags
     */
    private function renderProductionTags(array $entries): string
    {
        $tags = [];

        foreach ($entries as [$namespace, $path]) {
            $config = $this->resolveConfig($namespace);
            $manifest = $this->loadManifest($namespace, $config);

            $entryKey = $this->resolveManifestEntryKey($path, $manifest);
            if ($entryKey === null) {
                throw new TemplateRuntimeException(sprintf('Vite manifest entry "%s" was not found.', $path));
            }

            $visited = [];
            $cssFiles = $this->collectCssFiles($entryKey, $manifest, $visited);

            foreach ($cssFiles as $cssFile) {
                $href = $this->normalizeBuildUrl($cssFile, $config->assetBaseUrl);
                $tag = '<link rel="stylesheet" href="' . Escaper::attr($href) . '">';
                $deduped = $this->emitTag($tag);
                if ($deduped !== null) {
                    $tags[] = $deduped;
                }
            }

            $entryMeta = $manifest[$entryKey];
            if (!is_array($entryMeta) || !isset($entryMeta['file']) || !is_string($entryMeta['file'])) {
                throw new TemplateRuntimeException(sprintf('Invalid Vite manifest entry for "%s".', $path));
            }

            $entryFile = $entryMeta['file'];
            $src = $this->normalizeBuildUrl($entryFile, $config->assetBaseUrl);

            if ($this->isCssAssetPath($entryFile)) {
                $styleTag = '<link rel="stylesheet" href="' . Escaper::attr($src) . '">';
                $dedupedStyle = $this->emitTag($styleTag);
                if ($dedupedStyle !== null) {
                    $tags[] = $dedupedStyle;
                }
            } else {
                $scriptTag = '<script type="module" src="' . Escaper::attr($src) . '"></script>';
                $dedupedScript = $this->emitTag($scriptTag);
                if ($dedupedScript !== null) {
                    $tags[] = $dedupedScript;
                }
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Resolve a manifest key by exact or normalized path match.
     *
     * @param string $entry Requested entry path
     * @param array<string, mixed> $manifest Parsed manifest map
     */
    private function resolveManifestEntryKey(string $entry, array $manifest): ?string
    {
        if (isset($manifest[$entry])) {
            return $entry;
        }

        $normalized = ltrim($entry, '/');
        if (isset($manifest[$normalized])) {
            return $normalized;
        }

        return null;
    }

    /**
     * Collect CSS files from an entry and its static imports.
     *
     * @param string $entryKey Manifest key
     * @param array<string, mixed> $manifest Parsed manifest map
     * @param array<string, true> $visited Visited manifest keys
     * @return array<string>
     */
    private function collectCssFiles(string $entryKey, array $manifest, array &$visited): array
    {
        if (isset($visited[$entryKey])) {
            return [];
        }

        $visited[$entryKey] = true;

        $meta = $manifest[$entryKey] ?? null;
        if (!is_array($meta)) {
            return [];
        }

        $files = [];
        if (isset($meta['css']) && is_array($meta['css'])) {
            foreach ($meta['css'] as $cssFile) {
                if (is_string($cssFile) && $cssFile !== '') {
                    $files[] = $cssFile;
                }
            }
        }

        if (isset($meta['imports']) && is_array($meta['imports'])) {
            foreach ($meta['imports'] as $importKey) {
                if (!is_string($importKey)) {
                    continue;
                }

                if ($importKey === '') {
                    continue;
                }

                if (!isset($manifest[$importKey])) {
                    continue;
                }

                array_push($files, ...$this->collectCssFiles($importKey, $manifest, $visited));
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Load and decode the Vite manifest for the given config, caching per namespace.
     *
     * @param string|null $namespace Namespace key used for caching
     * @param \Sugar\Extension\Vite\ViteConfig $config Config to load the manifest from
     * @return array<string, mixed>
     */
    private function loadManifest(?string $namespace, ViteConfig $config): array
    {
        $cacheKey = $namespace ?? '';

        if (isset($this->manifests[$cacheKey])) {
            return $this->manifests[$cacheKey];
        }

        $manifestPath = $config->manifestPath;

        if ($manifestPath === null || trim($manifestPath) === '') {
            throw new TemplateRuntimeException('Vite manifest path is required in production mode.');
        }

        if (!is_file($manifestPath)) {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file was not found at "%s".',
                $manifestPath,
            ));
        }

        $manifestJson = file_get_contents($manifestPath);
        if (!is_string($manifestJson) || $manifestJson === '') {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file "%s" is empty or unreadable.',
                $manifestPath,
            ));
        }

        $decoded = json_decode($manifestJson, true);
        if (!is_array($decoded)) {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file "%s" contains invalid JSON.',
                $manifestPath,
            ));
        }

        $manifest = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $manifest[$key] = $value;
        }

        $this->manifests[$cacheKey] = $manifest;

        return $this->manifests[$cacheKey];
    }

    /**
     * Normalize an entry path against the given dev server URL.
     */
    private function normalizeDevUrl(string $entryPath, string $devServerUrl): string
    {
        $base = rtrim($devServerUrl, '/');
        $path = ltrim($entryPath, '/');

        return $base . '/' . $path;
    }

    /**
     * Normalize a built file path against the given build base URL.
     */
    private function normalizeBuildUrl(string $filePath, string $assetBaseUrl): string
    {
        $base = rtrim($this->normalizePublicPath($assetBaseUrl), '/');
        $path = ltrim($filePath, '/');

        return $base . '/' . $path;
    }

    /**
     * Normalize a path into absolute public URL path form.
     */
    private function normalizePublicPath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));

        if ($normalized === '') {
            return '/';
        }

        if (preg_match('/^(https?:)?\/\//i', $normalized) === 1) {
            return rtrim($normalized, '/');
        }

        $trimmed = trim($normalized, '/');

        if ($trimmed === '') {
            return '/';
        }

        return '/' . $trimmed;
    }

    /**
     * Check whether an emitted asset file path refers to CSS.
     */
    private function isCssAssetPath(string $filePath): bool
    {
        $parsedPath = parse_url($filePath, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : $filePath;
        $path = strtolower($path);

        return str_ends_with($path, '.css');
    }

    /**
     * Register a rendered tag if it has not been emitted before.
     */
    private function emitTag(string $tag): ?string
    {
        if (isset($this->emittedTags[$tag])) {
            return null;
        }

        $this->emittedTags[$tag] = true;

        return $tag;
    }
}
