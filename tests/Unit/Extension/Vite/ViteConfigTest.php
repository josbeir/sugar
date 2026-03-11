<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Vite;

use PHPUnit\Framework\TestCase;
use Sugar\Extension\Vite\ViteConfig;

/**
 * Tests ViteConfig value object construction and defaults.
 */
final class ViteConfigTest extends TestCase
{
    /**
     * Verify all properties are stored correctly when explicitly provided.
     */
    public function testStoresAllPropertiesFromConstructor(): void
    {
        $config = new ViteConfig(
            assetBaseUrl: '/build/',
            manifestPath: '/var/www/build/.vite/manifest.json',
            devServerUrl: 'http://localhost:5174',
            injectClient: false,
            defaultEntry: 'resources/js/app.ts',
        );

        $this->assertSame('/build/', $config->assetBaseUrl);
        $this->assertSame('/var/www/build/.vite/manifest.json', $config->manifestPath);
        $this->assertSame('http://localhost:5174', $config->devServerUrl);
        $this->assertFalse($config->injectClient);
        $this->assertSame('resources/js/app.ts', $config->defaultEntry);
    }

    /**
     * Verify optional properties default to null/true when not specified.
     */
    public function testOptionalPropertiesHaveCorrectDefaults(): void
    {
        $config = new ViteConfig(assetBaseUrl: '/build/');

        $this->assertNull($config->manifestPath);
        $this->assertNull($config->devServerUrl);
        $this->assertTrue($config->injectClient);
        $this->assertNull($config->defaultEntry);
    }
}
