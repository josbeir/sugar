<?php
declare(strict_types=1);

namespace Sugar\Extension\Vite\Runtime;

use Sugar\Core\Escape\Escaper;
use Sugar\Core\Exception\TemplateRuntimeException;

/**
 * Resolves and renders Vite asset tags at runtime.
 *
 * In development mode this emits `@vite/client` and entry module scripts.
 * In production mode this resolves entries from `manifest.json` and emits
 * stylesheet and module script tags.
 */
final class ViteAssetResolver
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $manifest = null;

    /**
     * @var array<string, true>
     */
    private array $emittedTags = [];

    private bool $clientInjected = false;

    /**
     * @param string $mode Resolver mode: `auto`, `dev`, or `prod`
     * @param bool $debug Whether engine debug mode is enabled
     * @param string|null $manifestPath Absolute path to Vite manifest file for production mode
     * @param string $assetBaseUrl Public URL base for emitted manifest assets
     * @param string $devServerUrl Vite dev server origin
     * @param bool $injectClient Whether to inject `@vite/client` in development mode
     * @param string|null $defaultEntry Optional default entry used when specification is boolean
     */
    public function __construct(
        private readonly string $mode,
        private readonly bool $debug,
        private readonly ?string $manifestPath,
        private readonly string $assetBaseUrl,
        private readonly string $devServerUrl,
        private readonly bool $injectClient,
        private readonly ?string $defaultEntry,
    ) {
        if (trim($this->assetBaseUrl) === '') {
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
     * Normalize directive specification into entry list.
     *
     * @param mixed $spec Entry specification
     * @return array<string>
     */
    private function normalizeEntries(mixed $spec): array
    {
        if ($spec === null || $spec === true) {
            return $this->defaultEntry === null ? [] : [$this->defaultEntry];
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
                $entries = [];
                foreach ($spec['entries'] as $entry) {
                    if (!is_string($entry)) {
                        continue;
                    }

                    $normalized = trim($entry);
                    if ($normalized !== '') {
                        $entries[] = $normalized;
                    }
                }

                return $entries;
            }

            $entries = [];
            foreach ($spec as $entry) {
                if (!is_string($entry)) {
                    continue;
                }

                $normalized = trim($entry);
                if ($normalized !== '') {
                    $entries[] = $normalized;
                }
            }

            return $entries;
        }

        throw new TemplateRuntimeException('s:vite expects a string, list, or options array expression.');
    }

    /**
     * Render development mode tags for entries.
     *
     * @param array<string> $entries Entry paths
     * @return string Rendered HTML tags
     */
    private function renderDevelopmentTags(array $entries): string
    {
        $tags = [];

        if ($this->injectClient && !$this->clientInjected) {
            $clientUrl = $this->normalizeDevUrl('@vite/client');
            $tag = '<script type="module" src="' . Escaper::attr($clientUrl) . '"></script>';
            $deduped = $this->emitTag($tag);
            if ($deduped !== null) {
                $tags[] = $deduped;
                $this->clientInjected = true;
            }
        }

        foreach ($entries as $entry) {
            $entryUrl = $this->normalizeDevUrl($entry);
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
     * @param array<string> $entries Entry paths
     * @return string Rendered HTML tags
     */
    private function renderProductionTags(array $entries): string
    {
        $manifest = $this->loadManifest();
        $tags = [];

        foreach ($entries as $entry) {
            $entryKey = $this->resolveManifestEntryKey($entry, $manifest);
            if ($entryKey === null) {
                throw new TemplateRuntimeException(sprintf('Vite manifest entry "%s" was not found.', $entry));
            }

            $visited = [];
            $cssFiles = $this->collectCssFiles($entryKey, $manifest, $visited);

            foreach ($cssFiles as $cssFile) {
                $href = $this->normalizeBuildUrl($cssFile);
                $tag = '<link rel="stylesheet" href="' . Escaper::attr($href) . '">';
                $deduped = $this->emitTag($tag);
                if ($deduped !== null) {
                    $tags[] = $deduped;
                }
            }

            $entryMeta = $manifest[$entryKey];
            if (!is_array($entryMeta) || !isset($entryMeta['file']) || !is_string($entryMeta['file'])) {
                throw new TemplateRuntimeException(sprintf('Invalid Vite manifest entry for "%s".', $entry));
            }

            $entryFile = $entryMeta['file'];
            $src = $this->normalizeBuildUrl($entryFile);

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
     * Load and decode the Vite manifest.
     *
     * @return array<string, mixed>
     */
    private function loadManifest(): array
    {
        if (is_array($this->manifest)) {
            return $this->manifest;
        }

        if ($this->manifestPath === null || trim($this->manifestPath) === '') {
            throw new TemplateRuntimeException('Vite manifest path is required in production mode.');
        }

        if (!is_file($this->manifestPath)) {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file was not found at "%s".',
                $this->manifestPath,
            ));
        }

        $manifestJson = file_get_contents($this->manifestPath);
        if (!is_string($manifestJson) || $manifestJson === '') {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file "%s" is empty or unreadable.',
                $this->manifestPath,
            ));
        }

        $decoded = json_decode($manifestJson, true);
        if (!is_array($decoded)) {
            throw new TemplateRuntimeException(sprintf(
                'Vite manifest file "%s" contains invalid JSON.',
                $this->manifestPath,
            ));
        }

        $manifest = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $manifest[$key] = $value;
        }

        $this->manifest = $manifest;

        return $this->manifest;
    }

    /**
     * Normalize an entry path against the dev server URL.
     */
    private function normalizeDevUrl(string $entryPath): string
    {
        $base = rtrim($this->devServerUrl, '/');
        $path = ltrim($entryPath, '/');

        return $base . '/' . $path;
    }

    /**
     * Normalize a built file path against the configured build base URL.
     */
    private function normalizeBuildUrl(string $filePath): string
    {
        $base = rtrim($this->normalizePublicPath($this->assetBaseUrl), '/');

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
