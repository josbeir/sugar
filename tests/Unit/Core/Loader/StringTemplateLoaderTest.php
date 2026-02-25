<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Loader\TemplateNamespaceDefinition;

final class StringTemplateLoaderTest extends TestCase
{
    public function testLoadReturnsExactCanonicalMatch(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@app/pages/home' => '<div>Exact</div>',
        ]);

        $this->assertSame('<div>Exact</div>', $loader->load('@app/pages/home'));
    }

    public function testLoadResolvesNonNamespacedInputToAppNamespace(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'pages/home.sugar.php' => '<div>Sugar</div>',
        ]);

        $this->assertSame('<div>Sugar</div>', $loader->load('pages/home'));
    }

    public function testLoadSupportsRelativeResolutionWithReferrer(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'layouts/base.sugar.php' => '<div>Base</div>',
        ]);

        $resolved = $loader->resolve('../layouts/base', '@app/pages/home.sugar.php');

        $this->assertSame('@app/layouts/base', $resolved);
        $this->assertSame('<div>Base</div>', $loader->load($resolved));
    }

    public function testLoadUsesRegisteredNamespaceRootsAndSuffixes(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@app/components/s-button.sugar.php' => '<button>Save</button>',
        ]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition(['components'], ['.sugar.php']));

        $this->assertSame('<button>Save</button>', $loader->load('@components/s-button'));
    }

    public function testLoadWithEmptyNamespaceRootAddsLogicalBasePath(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@components/shared/button.sugar.php' => '<button>Exact</button>',
        ]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition([''], ['.sugar.php']));

        $this->assertSame('<button>Exact</button>', $loader->load('@components/shared/button'));
    }

    public function testLoadResolvesNamespacedCandidateFromConfiguredRoot(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@components/components/button' => '<button>From root</button>',
        ]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition(['components'], ['.sugar.php']));

        $this->assertSame('<button>From root</button>', $loader->load('@components/button'));
    }

    public function testLoadFallsBackToAppNamespaceForExactCandidate(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@app/components/alert' => '<div>Alert</div>',
        ]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition(['components'], ['.sugar.php']));

        $this->assertSame('<div>Alert</div>', $loader->load('@components/alert'));
    }

    public function testLoadFallsBackToAppNamespaceForSuffixedCandidate(): void
    {
        $loader = new StringTemplateLoader(templates: [
            '@app/components/badge.sugar.php' => '<span>Badge</span>',
        ]);
        $loader->registerNamespace('components', new TemplateNamespaceDefinition(['components'], ['.sugar.php']));

        $this->assertSame('<span>Badge</span>', $loader->load('@components/badge'));
    }

    public function testLoadUsesConstructorConfiguredSuffixes(): void
    {
        $loader = new StringTemplateLoader(
            templates: [
                'pages/home.sugar.tpl' => '<div>Custom</div>',
            ],
            suffixes: ['.sugar.tpl'],
        );

        $this->assertSame('<div>Custom</div>', $loader->load('pages/home'));
    }

    public function testAddTemplateStoresCanonicalName(): void
    {
        $loader = new StringTemplateLoader();
        $loader->addTemplate('pages/home.sugar.php', '<div>Home</div>');

        $this->assertSame('<div>Home</div>', $loader->load('@app/pages/home.sugar.php'));
    }

    public function testExistsReflectsAvailability(): void
    {
        $loader = new StringTemplateLoader(templates: ['home.sugar.php' => '<div>Home</div>']);

        $this->assertTrue($loader->exists('home'));
        $this->assertFalse($loader->exists('missing'));
    }

    public function testSourcePathIsNullForInMemorySources(): void
    {
        $loader = new StringTemplateLoader(templates: ['home' => '<div>Home</div>']);

        $this->assertNull($loader->sourcePath('home'));
        $this->assertSame('@app/home', $loader->sourceId('home'));
    }

    public function testDiscoverReturnsSortedCanonicalNames(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'zeta.sugar.php' => 'z',
            'components/s-card.sugar.php' => '<div>card</div>',
            'alpha.sugar.php' => 'a',
        ]);

        $this->assertSame([
            '@app/alpha.sugar.php',
            '@app/components/s-card.sugar.php',
            '@app/zeta.sugar.php',
        ], $loader->discover('app'));

        $this->assertSame([
            '@app/components/s-card.sugar.php',
        ], $loader->discover('app', 'components'));
    }

    public function testResolveReturnsNamespaceRootWhenNoTemplateSegmentExists(): void
    {
        $loader = new StringTemplateLoader();
        $loader->registerNamespace('plugin', new TemplateNamespaceDefinition(['plugin-templates'], ['.sugar.php']));

        $this->assertSame('@plugin', $loader->resolve('@plugin'));
    }

    public function testResolveRelativePathToNamespaceRootReturnsCanonicalRootPath(): void
    {
        $loader = new StringTemplateLoader();
        $loader->registerNamespace('plugin', new TemplateNamespaceDefinition(['plugin-templates'], ['.sugar.php']));

        $this->assertSame('@plugin', $loader->resolve('./', '@plugin/layout'));
    }

    public function testDiscoverNamespaceSkipsTemplatesOutsideNamespaceRoot(): void
    {
        $loader = new StringTemplateLoader();
        $loader->registerNamespace('theme', new TemplateNamespaceDefinition(['theme'], ['.sugar.php']));
        $loader->registerNamespace('plugin', new TemplateNamespaceDefinition(['plugin'], ['.sugar.php']));
        $loader->addTemplate('@theme/components/button', '<button>Theme</button>');
        $loader->addTemplate('@plugin/components/button', '<button>Plugin</button>');

        $this->assertSame(['@theme/components/button'], $loader->discover('theme', 'components'));
    }

    public function testDiscoverReturnsEmptyArrayForUnknownNamespace(): void
    {
        $loader = new StringTemplateLoader(templates: ['home' => '<div>Home</div>']);

        $this->assertSame([], $loader->discover('unknown'));
    }

    public function testThrowsWhenTemplateNotFound(): void
    {
        $loader = new StringTemplateLoader();

        $this->expectException(TemplateNotFoundException::class);
        $loader->load('missing');
    }
}
