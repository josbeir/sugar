<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Engine;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Vite\ViteConfig;
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
                assetBaseUrl: '/build/',
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
            ->withExtension(new ViteExtension(assetBaseUrl: '/build/', mode: 'dev'))
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
                assetBaseUrl: '/build/',
                mode: 'prod',
                manifestPath: $this->manifestPath,
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
                assetBaseUrl: '/assets/build',
                mode: 'prod',
                manifestPath: $this->manifestPath,
            ))
            ->build();

        $output = $engine->render('vite-prod-custom-base-page');

        $this->assertStringContainsString('/assets/build/assets/site-l0sNRNKZ.js', $output);
        $this->assertStringNotContainsString('/webroot/build/assets/site-l0sNRNKZ.js', $output);
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
                assetBaseUrl: '/build/',
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
                assetBaseUrl: '/build/',
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
            ))
            ->build();

        $output = $engine->render('vite-dev-link-page');

        $this->assertStringContainsString('http://localhost:5173/scss/site.scss', $output);
        $this->assertStringNotContainsString('<link', $output);
    }

    /**
     * Verify custom element syntax works through ElementRoutingPass.
     */
    public function testRendersDevelopmentTagsFromElementClaimingSyntax(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'vite-dev-element-page' => '<s-vite src="resources/js/app.ts" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
            ))
            ->build();

        $output = $engine->render('vite-dev-element-page');

        $this->assertStringContainsString('http://localhost:5173/@vite/client', $output);
        $this->assertStringContainsString('http://localhost:5173/resources/js/app.ts', $output);
    }

    // ----------------------------------------------------------------
    // Namespace tests
    // ----------------------------------------------------------------

    /**
     * Verify @namespace/path entries in dev mode route to the namespace-specific dev server.
     */
    public function testRendersNamespacedDevelopmentTagsUsingSeparateDevServer(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'ns-dev-page' => '<s-template s:vite="\'@theme/resources/js/theme.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
                namespaces: [
                    'theme' => new ViteConfig(
                        assetBaseUrl: '/theme/build/',
                        devServerUrl: 'http://localhost:5174',
                    ),
                ],
            ))
            ->build();

        $output = $engine->render('ns-dev-page');

        $this->assertStringContainsString('http://localhost:5174/@vite/client', $output);
        $this->assertStringContainsString('http://localhost:5174/resources/js/theme.ts', $output);
        $this->assertStringNotContainsString('http://localhost:5173', $output);
    }

    /**
     * Verify @namespace/path entries in dev mode fall back to root dev server when none configured.
     */
    public function testNamespacedDevModeUsesRootDevServerWhenNotOverridden(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'ns-dev-fallback-page' => '<s-template s:vite="\'@theme/resources/js/theme.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
                namespaces: [
                    'theme' => new ViteConfig(assetBaseUrl: '/theme/build/'),
                ],
            ))
            ->build();

        $output = $engine->render('ns-dev-fallback-page');

        $this->assertStringContainsString('http://localhost:5173/resources/js/theme.ts', $output);
    }

    /**
     * Verify @namespace/path entries in prod mode resolve from the namespace-specific manifest.
     */
    public function testRendersNamespacedProductionAssetsFromSeparateManifest(): void
    {
        $defaultManifest = $this->createManifestFile([
            'resources/js/app.ts' => ['file' => 'assets/app-abc.js'],
        ]);
        $themeManifest = $this->createManifestFile([
            'resources/js/theme.ts' => [
                'file' => 'assets/theme-xyz.js',
                'css' => ['assets/theme-xyz.css'],
            ],
        ]);
        $this->manifestPath = $defaultManifest;

        $loader = new StringTemplateLoader(templates: [
            'ns-prod-page' => '<s-template s:vite="[\'resources/js/app.ts\', \'@theme/resources/js/theme.ts\']" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'prod',
                manifestPath: $defaultManifest,
                namespaces: [
                    'theme' => new ViteConfig(
                        assetBaseUrl: '/theme/build/',
                        manifestPath: $themeManifest,
                    ),
                ],
            ))
            ->build();

        $output = $engine->render('ns-prod-page');

        $this->assertStringContainsString('/build/assets/app-abc.js', $output);
        $this->assertStringContainsString('/theme/build/assets/theme-xyz.css', $output);
        $this->assertStringContainsString('/theme/build/assets/theme-xyz.js', $output);

        unlink($themeManifest);
    }

    /**
     * Verify that default entries from the root config still work with namespace entries mixed in.
     */
    public function testDefaultEntryIsUnaffectedByNamespaceEntries(): void
    {
        $manifest = $this->createManifestFile([
            'resources/js/app.ts' => ['file' => 'assets/app-abc.js'],
        ]);
        $this->manifestPath = $manifest;

        $loader = new StringTemplateLoader(templates: [
            'ns-default-entry-page' => '<s-template s:vite="true" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'prod',
                manifestPath: $manifest,
                defaultEntry: 'resources/js/app.ts',
                namespaces: [
                    'theme' => new ViteConfig(assetBaseUrl: '/theme/build/'),
                ],
            ))
            ->build();

        $output = $engine->render('ns-default-entry-page');

        $this->assertStringContainsString('/build/assets/app-abc.js', $output);
    }

    /**
     * Verify that @namespace/path with a namespace-scoped defaultEntry resolves correctly in prod.
     */
    public function testNamespacedDefaultEntryIsUsedWhenSpecIsTrue(): void
    {
        $themeManifest = $this->createManifestFile([
            'resources/js/theme.ts' => ['file' => 'assets/theme-xyz.js'],
        ]);

        $loader = new StringTemplateLoader(templates: [
            'ns-default-ns-entry-page' => '<s-template s:vite="true" /><s-template s:vite="\'@theme/resources/js/theme.ts\'" />',
        ]);

        $rootManifest = $this->createManifestFile([
            'resources/js/app.ts' => ['file' => 'assets/app-abc.js'],
        ]);
        $this->manifestPath = $rootManifest;

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'prod',
                manifestPath: $rootManifest,
                defaultEntry: 'resources/js/app.ts',
                namespaces: [
                    'theme' => new ViteConfig(
                        assetBaseUrl: '/theme/build/',
                        manifestPath: $themeManifest,
                    ),
                ],
            ))
            ->build();

        $output = $engine->render('ns-default-ns-entry-page');

        $this->assertStringContainsString('/build/assets/app-abc.js', $output);
        $this->assertStringContainsString('/theme/build/assets/theme-xyz.js', $output);

        unlink($themeManifest);
    }

    /**
     * Verify that an unregistered namespace throws a descriptive exception.
     */
    public function testUnregisteredNamespaceThrowsException(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'ns-unknown-page' => '<s-template s:vite="\'@unknown/resources/js/app.ts\'" />',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'dev',
                namespaces: [
                    'theme' => new ViteConfig(assetBaseUrl: '/theme/build/'),
                ],
            ))
            ->build();

        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessageMatches('/@unknown/');
        $this->expectExceptionMessageMatches('/@theme/');

        $engine->render('ns-unknown-page');
    }

    /**
     * Verify that namespaced entries in dev mode are each deduplicated independently.
     */
    public function testNamespacedEntriesAreDeduplicatedIndependently(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'ns-dedupe-page' => implode('', [
                '<s-template s:vite="\'@theme/resources/js/theme.ts\'" />',
                '<s-template s:vite="\'@theme/resources/js/theme.ts\'" />',
            ]),
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new ViteExtension(
                assetBaseUrl: '/build/',
                mode: 'dev',
                devServerUrl: 'http://localhost:5173',
                namespaces: [
                    'theme' => new ViteConfig(
                        assetBaseUrl: '/theme/build/',
                        devServerUrl: 'http://localhost:5174',
                    ),
                ],
            ))
            ->build();

        $output = $engine->render('ns-dedupe-page');

        $this->assertSame(1, substr_count($output, 'http://localhost:5174/@vite/client'));
        $this->assertSame(1, substr_count($output, 'resources/js/theme.ts'));
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
