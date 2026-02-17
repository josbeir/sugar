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

    public function testThrowsWhenTemplateNotFound(): void
    {
        $loader = new StringTemplateLoader();

        $this->expectException(TemplateNotFoundException::class);
        $loader->load('missing');
    }
}
