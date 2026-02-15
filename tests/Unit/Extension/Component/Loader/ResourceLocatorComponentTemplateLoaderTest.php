<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\Loader\ResourceLocatorComponentTemplateLoader;

final class ResourceLocatorComponentTemplateLoaderTest extends TestCase
{
    public function testLoadsComponentFromTemplateLoaderResources(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, [
            'components/s-card.sugar.php' => '<div class="card"><?= $slot ?></div>',
        ]);

        $loader = ResourceLocatorComponentTemplateLoader::forTemplateLoader(
            templateLoader: $templateLoader,
            config: $config,
            directories: ['components'],
        );

        $this->assertSame('<div class="card"><?= $slot ?></div>', $loader->loadComponent('card'));
        $this->assertSame('components/s-card.sugar.php', $loader->getComponentPath('card'));
    }
}
