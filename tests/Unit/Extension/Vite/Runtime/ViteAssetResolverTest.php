<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Vite\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Extension\Vite\Runtime\ViteAssetResolver;

/**
 * Tests ViteAssetResolver runtime behavior.
 */
final class ViteAssetResolverTest extends TestCase
{
    private ?string $manifestPath = null;

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null && is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
    }

    /**
     * Verify development output injects client and deduplicates repeated entries.
     */
    public function testDevelopmentModeInjectsClientAndDeduplicates(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $first = $resolver->render('resources/js/app.ts');
        $second = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('http://localhost:5173/@vite/client', $first);
        $this->assertStringContainsString('http://localhost:5173/resources/js/app.ts', $first);
        $this->assertSame('', $second);
    }

    /**
     * Verify production output resolves manifest entry css and script tags.
     */
    public function testProductionModeRendersManifestAssets(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('<link rel="stylesheet" href="/build/assets/app-def456.css">', $output);
        $this->assertStringContainsString('<script type="module" src="/build/assets/app-abc123.js"></script>', $output);
    }

    /**
     * Verify CSS entry points render stylesheet tags instead of module scripts.
     */
    public function testProductionModeCssEntryRendersStylesheetTag(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/assets/css/app.css' => [
                'file' => 'assets/app-BmtWQ3nA.css',
                'src' => 'resources/assets/css/app.css',
                'isEntry' => true,
                'name' => 'app',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/assets/css/app.css');

        $this->assertStringContainsString('<link rel="stylesheet" href="/build/assets/app-BmtWQ3nA.css">', $output);
        $this->assertStringNotContainsString('<script type="module" src="/build/assets/app-BmtWQ3nA.css"></script>', $output);
    }

    /**
     * Verify missing production manifest path raises a runtime exception.
     */
    public function testProductionModeWithoutManifestPathThrowsException(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite manifest path is required in production mode.');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify empty build base URL is rejected when no explicit asset base URL is configured.
     */
    public function testProductionModeWithoutConfiguredAssetBaseUrlThrowsException(): void
    {
        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite assetBaseUrl must be configured and non-empty.');

        new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );
    }

    /**
     * Verify boolean directive specification uses configured default entry.
     */
    public function testBooleanSpecificationUsesDefaultEntry(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: 'resources/js/default.ts',
        );

        $output = $resolver->render(true);

        $this->assertStringContainsString('http://localhost:5173/resources/js/default.ts', $output);
    }

    /**
     * Verify null specification without a default entry yields no output.
     */
    public function testNullSpecificationWithoutDefaultEntryReturnsEmptyString(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: null,
        );

        $output = $resolver->render(null);

        $this->assertSame('', $output);
    }

    /**
     * Verify options-array entry format is normalized and rendered.
     */
    public function testEntryOptionArrayRendersNormalizedEntry(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: null,
        );

        $output = $resolver->render(['entry' => '  resources/js/app.ts  ']);

        $this->assertStringContainsString('http://localhost:5173/resources/js/app.ts', $output);
    }

    /**
     * Verify entries-array format ignores non-string and empty entries.
     */
    public function testEntriesOptionArrayFiltersNonStringValues(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: null,
        );

        $output = $resolver->render([
            'entries' => [' resources/js/a.ts ', 42, '', 'resources/js/b.ts'],
        ]);

        $this->assertStringContainsString('http://localhost:5173/resources/js/a.ts', $output);
        $this->assertStringContainsString('http://localhost:5173/resources/js/b.ts', $output);
        $this->assertSame(2, substr_count($output, '<script type="module" src="http://localhost:5173/'));
    }

    /**
     * Verify list-array format ignores non-string and empty values.
     */
    public function testListSpecificationFiltersInvalidEntries(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: null,
        );

        $output = $resolver->render([' resources/js/a.ts ', null, '', 'resources/js/b.ts']);

        $this->assertStringContainsString('http://localhost:5173/resources/js/a.ts', $output);
        $this->assertStringContainsString('http://localhost:5173/resources/js/b.ts', $output);
    }

    /**
     * Verify unsupported specification type raises runtime exception.
     */
    public function testUnsupportedSpecificationTypeThrowsException(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'dev',
            debug: true,
            manifestPath: null,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('s:vite expects a string, list, or options array expression.');

        $resolver->render(123);
    }

    /**
     * Verify auto mode resolves to production behavior when debug is disabled.
     */
    public function testAutoModeUsesProductionWhenDebugDisabled(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'auto',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('/build/assets/app-abc123.js', $output);
        $this->assertStringNotContainsString('http://localhost:5173/@vite/client', $output);
    }

    /**
     * Verify production entry lookup also works for leading slash entry names.
     */
    public function testProductionModeResolvesLeadingSlashEntryNames(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('/resources/js/app.ts');

        $this->assertStringContainsString('/build/assets/app-abc123.js', $output);
    }

    /**
     * Verify manifest imports are traversed for CSS and duplicate tags are deduplicated.
     */
    public function testProductionModeCollectsCssFromImportsAndDeduplicates(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app.css'],
                'imports' => ['shared.js', ''],
            ],
            'shared.js' => [
                'file' => 'assets/shared.js',
                'css' => ['assets/shared.css', 'assets/app.css'],
                'imports' => ['missing.js', 123],
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertSame(1, substr_count($output, '/build/assets/app.css'));
        $this->assertSame(1, substr_count($output, '/build/assets/shared.css'));
    }

    /**
     * Verify missing manifest entries raise a runtime exception.
     */
    public function testProductionModeMissingManifestEntryThrowsException(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/other.ts' => [
                'file' => 'assets/other.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite manifest entry "resources/js/app.ts" was not found.');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify invalid manifest entry shape raises a runtime exception.
     */
    public function testProductionModeInvalidManifestEntryThrowsException(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'css' => ['assets/app.css'],
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Invalid Vite manifest entry for "resources/js/app.ts".');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify invalid JSON in manifest file raises a runtime exception.
     */
    public function testProductionModeInvalidManifestJsonThrowsException(): void
    {
        $this->manifestPath = sys_get_temp_dir() . '/sugar-vite-invalid-' . uniqid('', true) . '.json';
        file_put_contents($this->manifestPath, '{invalid-json');

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('contains invalid JSON');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify missing manifest file path raises a runtime exception.
     */
    public function testProductionModeWithMissingManifestFileThrowsException(): void
    {
        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: sys_get_temp_dir() . '/sugar-vite-missing-' . uniqid('', true) . '.json',
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite manifest file was not found at');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify decoded manifests with non-string keys are ignored safely.
     */
    public function testProductionModeIgnoresNonStringManifestKeys(): void
    {
        $this->manifestPath = sys_get_temp_dir() . '/sugar-vite-list-' . uniqid('', true) . '.json';
        file_put_contents($this->manifestPath, json_encode([
            ['file' => 'assets/app.js'],
        ], JSON_THROW_ON_ERROR));

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite manifest entry "resources/js/app.ts" was not found.');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify CSS assets with query strings still render stylesheet tags.
     */
    public function testProductionModeCssAssetWithQueryStringRendersStylesheetTag(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/assets/css/app.css' => [
                'file' => 'assets/app-BmtWQ3nA.css?v=1',
                'isEntry' => true,
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/assets/css/app.css');

        $this->assertStringContainsString('<link rel="stylesheet" href="/build/assets/app-BmtWQ3nA.css?v=1">', $output);
        $this->assertStringNotContainsString('<script type="module"', $output);
    }

    /**
     * Verify manifest decode happens once and subsequent renders use in-memory cache.
     */
    public function testProductionModeReusesLoadedManifestAcrossRenders(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $first = $resolver->render('resources/js/app.ts');
        $second = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('/build/assets/app-abc123.js', $first);
        $this->assertSame('', $second);
    }

    /**
     * Verify recursive CSS collection handles cycles and non-array imported entries.
     */
    public function testProductionModeCollectCssHandlesCyclesAndNonArrayImports(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app.js',
                'imports' => ['resources/js/app.ts', 'dep.ts'],
                'css' => ['assets/app.css'],
            ],
            'dep.ts' => 'invalid-meta',
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertSame(1, substr_count($output, '/build/assets/app.css'));
        $this->assertStringContainsString('/build/assets/app.js', $output);
    }

    /**
     * Verify empty manifest files are rejected as unreadable production manifests.
     */
    public function testProductionModeWithEmptyManifestFileThrowsException(): void
    {
        $this->manifestPath = sys_get_temp_dir() . '/sugar-vite-empty-' . uniqid('', true) . '.json';
        file_put_contents($this->manifestPath, '');

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('is empty or unreadable');

        $resolver->render('resources/js/app.ts');
    }

    /**
     * Verify slash-only build base normalizes to root slash during production rendering.
     */
    public function testProductionModeSlashOnlyBuildBaseNormalizesToRoot(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '///',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('<script type="module" src="/assets/app.js"></script>', $output);
    }

    /**
     * Verify single-slash build base also normalizes to root slash.
     */
    public function testProductionModeSingleSlashBuildBaseNormalizesToRoot(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app.js',
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('<script type="module" src="/assets/app.js"></script>', $output);
    }

    /**
     * Verify explicit asset base URL takes precedence over build base normalization.
     */
    public function testProductionModeUsesExplicitAssetBaseUrlWhenConfigured(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/assets/js/site.js' => [
                'file' => 'assets/site-l0sNRNKZ.js',
                'isEntry' => true,
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: '/assets/build',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/assets/js/site.js');

        $this->assertStringContainsString('<script type="module" src="/assets/build/assets/site-l0sNRNKZ.js"></script>', $output);
        $this->assertStringNotContainsString('<script type="module" src="/build/assets/site-l0sNRNKZ.js"></script>', $output);
    }

    /**
     * Verify absolute URL asset base paths are preserved for CDN usage.
     */
    public function testProductionModeSupportsAbsoluteAssetBaseUrl(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/assets/js/site.js' => [
                'file' => 'assets/site-l0sNRNKZ.js',
                'isEntry' => true,
            ],
        ]);

        $resolver = new ViteAssetResolver(
            mode: 'prod',
            debug: false,
            manifestPath: $this->manifestPath,
            assetBaseUrl: 'https://cdn.example.com/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/assets/js/site.js');

        $this->assertStringContainsString('<script type="module" src="https://cdn.example.com/build/assets/site-l0sNRNKZ.js"></script>', $output);
    }

    /**
     * Create a temporary Vite manifest JSON file for tests.
     *
     * @param array<string, mixed> $manifest Manifest payload
     */
    private function createManifestFile(array $manifest): string
    {
        $path = sys_get_temp_dir() . '/sugar-vite-manifest-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $path;
    }
}
