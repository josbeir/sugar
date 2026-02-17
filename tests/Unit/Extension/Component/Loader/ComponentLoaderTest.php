<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Loader\ComponentLoader;

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
            directories: ['components'],
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
            directories: ['components'],
        );

        $this->assertSame('@app/components/s-alert', $componentLoader->getComponentFilePath('alert'));
    }

    public function testThrowsComponentNotFoundWhenMissing(): void
    {
        $loader = new StringTemplateLoader();

        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: new SugarConfig(),
            directories: ['components'],
        );

        $this->expectException(ComponentNotFoundException::class);
        $componentLoader->loadComponent('missing');
    }
}
