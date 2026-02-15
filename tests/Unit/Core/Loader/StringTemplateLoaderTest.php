<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\ComponentNotFoundException;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\StringTemplateLoader;

/**
 * Tests for StringTemplateLoader
 */
final class StringTemplateLoaderTest extends TestCase
{
    public function testConstructorAcceptsTemplatesAndComponents(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'home' => '<div>Home</div>',
                'about' => '<div>About</div>',
            ],
            components: [
                'button' => '<button><?= $slot ?></button>',
            ],
        );

        $this->assertSame('<div>Home</div>', $loader->load('home'));
        $this->assertSame('<button><?= $slot ?></button>', $loader->loadComponent('button'));
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

    public function testLoadComponentReturnsComponentSource(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: ['button' => '<button class="btn"><?= $slot ?></button>'],
        );

        $this->assertSame('<button class="btn"><?= $slot ?></button>', $loader->loadComponent('button'));
    }

    public function testLoadComponentThrowsExceptionWhenNotFound(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "button" not found');

        $loader->loadComponent('button');
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

    public function testAddComponentAddsNewComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());
        $loader->addComponent('button', '<button><?= $slot ?></button>');

        $this->assertSame('<button><?= $slot ?></button>', $loader->loadComponent('button'));
    }

    public function testAddComponentOverwritesExistingComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig(), components: ['button' => '<button>Original</button>']);

        $loader->addComponent('button', '<button>Updated</button>');

        $this->assertSame('<button>Updated</button>', $loader->loadComponent('button'));
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

    public function testHasComponentReturnsTrueForExistingComponent(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: ['button' => '<button><?= $slot ?></button>'],
        );

        $this->assertTrue($loader->hasComponent('button'));
    }

    public function testHasComponentReturnsFalseForNonExistingComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertFalse($loader->hasComponent('button'));
    }

    public function testIsComponentReturnsTrueForRegisteredComponent(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: ['button' => '<button><?= $slot ?></button>'],
        );

        $this->assertTrue($loader->isComponent('s-button'));
    }

    public function testIsComponentReturnsFalseForNonExistingComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertFalse($loader->isComponent('s-button'));
    }

    public function testIsComponentReturnsFalseForFragmentElement(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(config: $config);

        // s-template is the fragment element, not a component
        $this->assertFalse($loader->isComponent($config->getFragmentElement()));
    }

    public function testIsComponentReturnsFalseForElementWithoutPrefix(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: ['button' => '<button><?= $slot ?></button>'],
        );

        // 'button' without prefix 's-' is not considered a component element
        $this->assertFalse($loader->isComponent('button'));
    }

    public function testGetComponentNameStripsPrefix(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertSame('button', $loader->getComponentName('s-button'));
        $this->assertSame('alert', $loader->getComponentName('s-alert'));
    }

    public function testGetComponentsReturnsAllComponents(): void
    {
        $components = [
            'button' => '<button><?= $slot ?></button>',
            'alert' => '<div class="alert"><?= $slot ?></div>',
        ];
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: $components,
        );

        $this->assertSame($components, $loader->getComponents());
    }

    public function testGetComponentsReturnsEmptyArrayWhenNoComponents(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->assertSame([], $loader->getComponents());
    }

    public function testGetComponentPathReturnsVirtualPath(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            components: ['button' => '<button><?= $slot ?></button>'],
        );

        $path = $loader->getComponentPath('button');
        $this->assertSame('components/button.sugar.php', $path);
    }

    public function testGetComponentPathThrowsExceptionForUnknownComponent(): void
    {
        $loader = new StringTemplateLoader(config: new SugarConfig());

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "unknown" not found');

        $loader->getComponentPath('unknown');
    }
}
