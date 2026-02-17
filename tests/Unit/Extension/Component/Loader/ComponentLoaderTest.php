<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Loader\TemplateNamespaceDefinition;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Loader\ComponentLoader;
use Throwable;

final class ComponentLoaderTest extends TestCase
{
    public function testLoadsComponentFromRegisteredNamespace(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'components/s-card.sugar.php' => '<div class="card"><?= $slot ?></div>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            componentDirectories: ['components'],
        );

        $this->assertSame('<div class="card"><?= $slot ?></div>', $componentLoader->loadComponent('card'));
        $this->assertSame('@app/components/s-card', $componentLoader->getComponentPath('card'));
    }

    public function testReturnsStableFilePathFallbackForInMemorySources(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'components/s-alert.sugar.php' => '<div>alert</div>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            componentDirectories: ['components'],
        );

        $this->assertSame('@app/components/s-alert', $componentLoader->getComponentFilePath('alert'));
    }

    public function testThrowsComponentNotFoundWhenMissing(): void
    {
        $loader = new StringTemplateLoader();

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            componentDirectories: ['components'],
        );

        $this->expectException(ComponentNotFoundException::class);
        $componentLoader->loadComponent('missing');
    }

    public function testAutoDetectsNamespacesFromLoader(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'components/s-button.sugar.php' => '<button><?= $slot ?></button>',
        ]);
        $loader->registerNamespace('plugin-auth', new TemplateNamespaceDefinition([]));

        // ComponentLoader with empty templateNamespaces should auto-detect
        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            templateNamespaces: [],
            componentDirectories: ['components'],
        );

        $this->assertSame('<button><?= $slot ?></button>', $componentLoader->loadComponent('button'));
    }

    public function testSearchesMultipleNamespacesInOrder(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'components/s-button.sugar.php' => '<button class="app">App Button</button>',
            'ui/s-button.sugar.php' => '<button class="plugin">Plugin Button</button>',
        ]);
        $loader->registerNamespace('shared-ui', new TemplateNamespaceDefinition([]));

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            templateNamespaces: ['app', 'shared-ui'],
            componentDirectories: ['components', 'ui'],
        );

        // Should find in @app/components first
        $content = $componentLoader->loadComponent('button');
        $this->assertStringContainsString('app', $content);
    }

    public function testSearchesMultipleComponentDirectories(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'shared/s-icon.sugar.php' => '<svg>icon</svg>',
            'components/s-card.sugar.php' => '<div>card</div>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            componentDirectories: ['components', 'shared'],
        );

        // Card in components
        $this->assertStringContainsString('card', $componentLoader->loadComponent('card'));

        // Icon in shared
        $this->assertStringContainsString('icon', $componentLoader->loadComponent('icon'));
    }

    public function testFallsBackToFirstNamespaceFirstDirectoryWhenNotFound(): void
    {
        $loader = new StringTemplateLoader(templates: [
            'components/s-fallback.sugar.php' => '<div>fallback</div>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            templateNamespaces: ['app'],
            componentDirectories: ['components'],
        );

        // When not found anywhere, fallback path is used in exception
        $this->expectException(ComponentNotFoundException::class);
        $componentLoader->loadComponent('nonexistent');
    }

    public function testNormalizesComponentNameWithPrefix(): void
    {
        $config = new SugarConfig(elementPrefix: 'x-');
        $loader = new StringTemplateLoader(templates: [
            'components/x-alert.sugar.php' => '<div>alert</div>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: $config,
            componentDirectories: ['components'],
        );

        // Component name 'alert' should become 'x-alert'
        $this->assertStringContainsString('alert', $componentLoader->loadComponent('alert'));
        $this->assertSame('@app/components/x-alert', $componentLoader->getComponentPath('alert'));
    }

    public function testDoesNotDuplicatePrefixWhenNameAlreadyPrefixed(): void
    {
        $config = new SugarConfig(elementPrefix: 'x-');
        $loader = new StringTemplateLoader(templates: [
            'components/x-badge.sugar.php' => '<span>badge</span>',
        ]);

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: $config,
            componentDirectories: ['components'],
        );

        $this->assertSame('<span>badge</span>', $componentLoader->loadComponent('x-badge'));
        $this->assertSame('@app/components/x-badge', $componentLoader->getComponentPath('x-badge'));
    }

    public function testFallsBackToNamespaceRootWhenDirectoryIsEmpty(): void
    {
        $loader = new StringTemplateLoader();

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            templateNamespaces: ['app'],
            componentDirectories: [''],
        );

        try {
            $componentLoader->loadComponent('badge');
            $this->fail('Expected ComponentNotFoundException to be thrown.');
        } catch (ComponentNotFoundException $componentNotFoundException) {
            $this->assertInstanceOf(Throwable::class, $componentNotFoundException->getPrevious());
            $previous = $componentNotFoundException->getPrevious();
            $this->assertInstanceOf(Throwable::class, $previous);
            $this->assertStringContainsString('@app/s-badge', $previous->getMessage());
        }
    }
}
