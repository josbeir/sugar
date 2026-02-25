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
