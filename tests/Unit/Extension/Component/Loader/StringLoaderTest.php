<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Extension\Component\Loader\StringLoader;

final class StringLoaderTest extends TestCase
{
    public function testUsesConfiguredSuffixForSeededComponents(): void
    {
        $loader = new StringLoader(
            config: new SugarConfig(),
            components: [
                'card' => '<div class="card"><?= $slot ?></div>',
            ],
            suffixes: ['.sugar.tpl'],
        );

        $this->assertStringContainsString('class="card"', $loader->loadComponent('card'));
        $this->assertSame('@app/components/s-card', $loader->getComponentPath('card'));
    }

    public function testUsesConfiguredSuffixWhenAddingComponents(): void
    {
        $loader = new StringLoader(
            config: new SugarConfig(),
            suffixes: ['.sugar.tpl'],
        );

        $loader->addComponent('badge', '<span class="badge"><?= $slot ?></span>');

        $this->assertStringContainsString('class="badge"', $loader->loadComponent('badge'));
    }
}
