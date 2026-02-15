<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\StringTemplateLoader;

/**
 * Tests for StringTemplateLoader
 */
final class StringTemplateLoaderTest extends TestCase
{
    public function testConstructorAcceptsTemplates(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'home' => '<div>Home</div>',
                'about' => '<div>About</div>',
            ],
        );

        $this->assertSame('<div>Home</div>', $loader->load('home'));
    }

    public function testLoadReturnsExactMatch(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['pages/home' => '<div>Exact</div>'],
        );

        $this->assertSame('<div>Exact</div>', $loader->load('pages/home'));
    }

    public function testLoadTriesSugarPhpExtension(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['pages/home.sugar.php' => '<div>Sugar</div>'],
        );

        // Should find pages/home.sugar.php when requesting pages/home
        $this->assertSame('<div>Sugar</div>', $loader->load('pages/home'));
    }

    public function testLoadTriesPhpExtension(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['pages/home.php' => '<div>PHP</div>'],
        );

        // Should find pages/home.php when requesting pages/home
        $this->assertSame('<div>PHP</div>', $loader->load('pages/home'));
    }

    public function testLoadPrioritizesExactMatchOverExtensions(): void
    {
        $loader = new StringTemplateLoader(
            templates: [
                'home' => '<div>Exact</div>',
                'home.sugar.php' => '<div>Sugar</div>',
                'home.php' => '<div>PHP</div>',
            ],
        );

        $this->assertSame('<div>Exact</div>', $loader->load('home'));
    }

    public function testLoadPrioritizesSugarPhpOverPhp(): void
    {
        $loader = new StringTemplateLoader(
            templates: [
                'home.sugar.php' => '<div>Sugar</div>',
                'home.php' => '<div>PHP</div>',
            ],
        );

        $this->assertSame('<div>Sugar</div>', $loader->load('home'));
    }

    public function testLoadThrowsExceptionWhenTemplateNotFound(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('Template "nonexistent" not found');

        $loader->load('nonexistent');
    }

    public function testAddTemplateAddsNewTemplate(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());
        $loader->addTemplate('pages/home', '<div>Home</div>');

        $this->assertSame('<div>Home</div>', $loader->load('pages/home'));
    }

    public function testAddTemplateOverwritesExistingTemplate(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig(), templates: ['home' => '<div>Original</div>']);

        $loader->addTemplate('home', '<div>Updated</div>');

        $this->assertSame('<div>Updated</div>', $loader->load('home'));
    }

    public function testLoadNormalizesPathsWithLeadingSlash(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['pages/home' => '<div>Home</div>'],
        );

        $this->assertSame('<div>Home</div>', $loader->load('/pages/home'));
    }

    public function testLoadNormalizesPathsWithDotSegments(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['layouts/base' => '<html></html>'],
        );

        // ./layouts/base should resolve to layouts/base
        $this->assertSame('<html></html>', $loader->load('./layouts/base'));
    }

    public function testLoadNormalizesPathsWithParentSegments(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['layouts/base' => '<html></html>'],
        );

        // pages/../layouts/base should resolve to layouts/base
        $this->assertSame('<html></html>', $loader->load('pages/../layouts/base'));
    }

    public function testLoadHandlesComplexPathNormalization(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['shared/components/button' => '<button></button>'],
        );

        // pages/../../shared/./components/../components/button
        // → shared/components/button
        $this->assertSame(
            '<button></button>',
            $loader->load('pages/../../shared/./components/../components/button'),
        );
    }

    public function testEmptyPathSegmentsAreIgnored(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: ['pages/home' => '<div>Home</div>'],
        );

        // Multiple slashes should be collapsed
        $this->assertSame('<div>Home</div>', $loader->load('pages//home'));
    }

    public function testResolveHandlesAbsolutePaths(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertSame('pages/home', $loader->resolve('/pages/home'));
        $this->assertSame('layouts/base', $loader->resolve('/layouts/base'));
    }

    public function testResolveHandlesRelativePathsWithoutCurrentTemplate(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertSame('pages/home', $loader->resolve('pages/home'));
        $this->assertSame('layouts/base', $loader->resolve('./layouts/base'));
    }

    public function testResolveHandlesRelativePathsWithCurrentTemplate(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        // From pages/home, include ../layouts/base
        $this->assertSame('layouts/base', $loader->resolve('../layouts/base', 'pages/home'));

        // From pages/posts/view, include ../../layouts/base
        $this->assertSame('layouts/base', $loader->resolve('../../layouts/base', 'pages/posts/view'));
    }

    public function testResolveHandlesSameLevelIncludes(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        // From pages/home, include header (same directory)
        $this->assertSame('pages/header', $loader->resolve('header', 'pages/home'));

        // From pages/home, include ./header (explicit same directory)
        $this->assertSame('pages/header', $loader->resolve('./header', 'pages/home'));
    }

    public function testResolveNormalizesComplexPaths(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertSame(
            'shared/components/button',
            $loader->resolve('../../shared/./components/../components/button', 'pages/posts/view'),
        );
    }

    public function testListTemplatePathsReturnsSortedPaths(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'zeta.sugar.php' => 'z',
                'components/s-card.sugar.php' => '<div>card</div>',
                'alpha.sugar.php' => 'a',
            ],
        );

        $this->assertSame([
            'alpha.sugar.php',
            'components/s-card.sugar.php',
            'zeta.sugar.php',
        ], $loader->listTemplatePaths());
    }

    public function testListTemplatePathsFiltersByNormalizedPrefix(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'components/s-alert.sugar.php' => '<div>alert</div>',
                'components/forms/s-input.sugar.php' => '<input>',
                'partials/header.sugar.php' => '<header></header>',
            ],
        );

        $this->assertSame([
            'components/forms/s-input.sugar.php',
            'components/s-alert.sugar.php',
        ], $loader->listTemplatePaths('/components/./'));
    }
}
