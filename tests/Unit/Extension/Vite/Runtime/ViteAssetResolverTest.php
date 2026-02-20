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
            buildBaseUrl: '/build/',
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
            buildBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $output = $resolver->render('resources/js/app.ts');

        $this->assertStringContainsString('<link rel="stylesheet" href="/build/assets/app-def456.css">', $output);
        $this->assertStringContainsString('<script type="module" src="/build/assets/app-abc123.js"></script>', $output);
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
            buildBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: true,
            defaultEntry: null,
        );

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Vite manifest path is required in production mode.');

        $resolver->render('resources/js/app.ts');
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
            buildBaseUrl: '/build/',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: 'resources/js/default.ts',
        );

        $output = $resolver->render(true);

        $this->assertStringContainsString('http://localhost:5173/resources/js/default.ts', $output);
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
