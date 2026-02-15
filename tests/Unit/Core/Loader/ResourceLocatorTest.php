<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\TemplateNotFoundException;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Loader\ResourceLocator;
use Sugar\Core\Loader\ResourceTypeDefinition;
use Sugar\Core\Loader\StringTemplateLoader;

final class ResourceLocatorTest extends TestCase
{
    public function testResolvesAndLoadsRegisteredResourceType(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, [
            'components/s-button.sugar.php' => '<button><?= $slot ?></button>',
        ]);

        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: 'component',
            directories: ['components'],
        ));

        $this->assertTrue($locator->has('component', 'button'));
        $this->assertSame('components/s-button.sugar.php', $locator->path('component', 'button'));
        $this->assertSame('<button><?= $slot ?></button>', $locator->load('component', 'button'));
    }

    public function testThrowsForUnknownResourceName(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, []);

        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: 'component',
            directories: ['components'],
        ));

        $this->expectException(TemplateNotFoundException::class);
        $locator->path('component', 'missing');
    }

    public function testThrowsForUnknownResourceType(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, []);

        $locator = new ResourceLocator($templateLoader, $config);

        $this->expectException(TemplateRuntimeException::class);
        $locator->has('component', 'missing');
    }

    public function testResolvesFilePathThroughUnderlyingLoader(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, [
            'components/s-alert.sugar.php' => '<div><?= $slot ?></div>',
        ]);

        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: 'component',
            directories: ['components'],
        ));

        $this->assertSame('components/s-alert.sugar.php', $locator->filePath('component', 'alert'));
    }

    public function testSupportsDefinitionsWithoutPrefixStrippingOrFragmentFiltering(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, [
            'components/s-template.sugar.php' => '<div>fragment-name</div>',
            'components/s-button.sugar.php' => '<button>ok</button>',
        ]);

        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: 'raw_component',
            directories: ['components'],
            stripElementPrefix: false,
            ignoreFragmentElement: false,
        ));

        $this->assertTrue($locator->has('raw_component', 's-template'));
        $this->assertTrue($locator->has('raw_component', 's-button'));
        $this->assertSame('components/s-button.sugar.php', $locator->path('raw_component', 's-button'));
    }

    public function testSkipsWrongSuffixAndFragmentElementForDefaultComponentDefinition(): void
    {
        $config = new SugarConfig();
        $templateLoader = new StringTemplateLoader($config, [
            'components/s-template.sugar.php' => '<div>fragment</div>',
            'components/s-readme.txt' => 'ignored',
            'components/s-alert.sugar.php' => '<div>alert</div>',
        ]);

        $locator = new ResourceLocator($templateLoader, $config);
        $locator->registerType(new ResourceTypeDefinition(
            name: 'component',
            directories: ['components'],
        ));

        $this->assertFalse($locator->has('component', 'template'));
        $this->assertFalse($locator->has('component', 'readme'));
        $this->assertTrue($locator->has('component', 'alert'));
    }
}
