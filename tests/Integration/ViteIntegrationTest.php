<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Engine;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Vite\ViteExtension;

/**
 * Integration tests for Vite extension rendering behavior.
 */
final class ViteIntegrationTest extends TestCase
{
    private ?string $manifestPath = null;

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null && is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
    }

    /**
     * Verify s:vite renders development client and entry script tags.
     */
    public function testRendersDevelopmentViteTags(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'vite-dev-page' => '<s-template s:vite="\'resources/js/app.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
            ))
            ->build();

        $output = $engine->render('vite-dev-page');

        $this->assertStringContainsString('http://localhost:5173/@vite/client', $output);
        $this->assertStringContainsString('http://localhost:5173/resources/js/app.ts', $output);
    }

    /**
     * Verify repeated s:vite calls deduplicate emitted tags per render.
     */
    public function testDeduplicatesRepeatedViteEntriesInDevelopmentMode(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'vite-dev-dedupe-page' => '<s-template s:vite="\'resources/js/app.ts\'" /><s-template s:vite="\'resources/js/app.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(mode: 'dev'))
            ->build();

        $output = $engine->render('vite-dev-dedupe-page');

        $this->assertSame(1, substr_count($output, '@vite/client'));
        $this->assertSame(1, substr_count($output, 'resources/js/app.ts'));
    }

    /**
     * Verify s:vite resolves production assets from Vite manifest.
     */
    public function testRendersProductionAssetsFromManifest(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
            ],
        ]);

        $loader = new StringTemplateLoader(templates: [
            'vite-prod-page' => '<s-template s:vite="\'resources/js/app.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                mode: 'prod',
                manifestPath: $this->manifestPath,
                buildBaseUrl: '/build/',
            ))
            ->build();

        $output = $engine->render('vite-prod-page');

        $this->assertStringContainsString('/build/assets/app-def456.css', $output);
        $this->assertStringContainsString('/build/assets/app-abc123.js', $output);
    }

    /**
     * Verify explicit asset base URL is used for production manifest output.
     */
    public function testRendersProductionAssetsUsingExplicitAssetBaseUrl(): void
    {
        $this->manifestPath = $this->createManifestFile([
            'resources/assets/js/site.js' => [
                'file' => 'assets/site-l0sNRNKZ.js',
                'isEntry' => true,
            ],
        ]);

        $loader = new StringTemplateLoader(templates: [
            'vite-prod-custom-base-page' => '<s-template s:vite="\'resources/assets/js/site.js\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                mode: 'prod',
                manifestPath: $this->manifestPath,
                buildBaseUrl: '/home/josbeir/Sites/sugar_app/webroot/build',
                assetBaseUrl: '/assets/build',
            ))
            ->build();

        $output = $engine->render('vite-prod-custom-base-page');

        $this->assertStringContainsString('/assets/build/assets/site-l0sNRNKZ.js', $output);
        $this->assertStringNotContainsString('/home/josbeir/Sites/sugar_app/webroot/build/assets/site-l0sNRNKZ.js', $output);
    }

    /**
     * Verify bare path syntax works without nested PHP string quotes.
     */
    public function testRendersDevelopmentTagsFromBarePathSyntax(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'vite-dev-bare-path-page' => '<s-template s:vite="resources/scss/site.scss" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
            ))
            ->build();

        $output = $engine->render('vite-dev-bare-path-page');

        $this->assertStringContainsString('http://localhost:5173/resources/scss/site.scss', $output);
    }

    /**
     * Verify s:vite works on void HTML elements without preserving the wrapper.
     */
    public function testRendersDevelopmentTagsFromLinkDirectiveUsage(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'vite-dev-link-page' => '<link s:vite="scss/site.scss" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
            ))
            ->build();

        $output = $engine->render('vite-dev-link-page');

        $this->assertStringContainsString('http://localhost:5173/scss/site.scss', $output);
        $this->assertStringNotContainsString('<link', $output);
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
