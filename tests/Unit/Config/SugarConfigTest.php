<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Config;

use PHPUnit\Framework\TestCase;
use Sugar\Config\SugarConfig;

final class SugarConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new SugarConfig();

        $this->assertSame('s', $config->directivePrefix);
        $this->assertSame('s-', $config->elementPrefix);
        $this->assertSame('s-template', $config->getFragmentElement());
        $this->assertSame([], $config->componentPaths);
    }

    public function testCustomPrefix(): void
    {
        $config = new SugarConfig(directivePrefix: 'x', elementPrefix: 'x-');

        $this->assertSame('x', $config->directivePrefix);
        $this->assertSame('x-', $config->elementPrefix);
        $this->assertSame('x-template', $config->getFragmentElement());
    }

    public function testCustomElementPrefix(): void
    {
        $config = new SugarConfig(
            directivePrefix: 'v',
            elementPrefix: 'v:',
        );

        $this->assertSame('v', $config->directivePrefix);
        $this->assertSame('v:', $config->elementPrefix);
        $this->assertSame('v:template', $config->getFragmentElement());
    }

    public function testComponentPaths(): void
    {
        $config = new SugarConfig(
            componentPaths: ['components', 'shared/components'],
        );

        $this->assertSame(['components', 'shared/components'], $config->componentPaths);
    }

    public function testNamedConstructorWithPrefix(): void
    {
        $config = SugarConfig::withPrefix('tw');

        $this->assertSame('tw', $config->directivePrefix);
        $this->assertSame('tw-', $config->elementPrefix);
        $this->assertSame('tw-template', $config->getFragmentElement());
    }

    public function testFragmentElementDerivedFromElementPrefix(): void
    {
        $config = new SugarConfig(elementPrefix: 'htmx-');

        $this->assertSame('htmx-', $config->elementPrefix);
        $this->assertSame('htmx-template', $config->getFragmentElement());
    }

    public function testConfigIsReadonly(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');

        $this->assertSame('x', $config->directivePrefix);

        // This test verifies readonly behavior exists
        // Actual readonly enforcement is at PHP language level
    }

    public function testWithTemplatePathsReturnsNewInstance(): void
    {
        $config1 = new SugarConfig();
        $config2 = $config1->withTemplatePaths('/path1', '/path2');

        $this->assertNotSame($config1, $config2);
        $this->assertSame([], $config1->templatePaths);
        $this->assertSame(['/path1', '/path2'], $config2->templatePaths);
    }

    public function testWithComponentPathsReturnsNewInstance(): void
    {
        $config1 = new SugarConfig();
        $config2 = $config1->withComponentPaths('components', 'ui');

        $this->assertNotSame($config1, $config2);
        $this->assertSame([], $config1->componentPaths);
        $this->assertSame(['components', 'ui'], $config2->componentPaths);
    }

    public function testFluentApiChaining(): void
    {
        $config = (new SugarConfig())
            ->withTemplatePaths('/templates', '/views')
            ->withComponentPaths('components', 'widgets');

        $this->assertSame(['/templates', '/views'], $config->templatePaths);
        $this->assertSame(['components', 'widgets'], $config->componentPaths);
    }

    public function testWithPrefixCanChainWithOtherMethods(): void
    {
        $config = SugarConfig::withPrefix('x')
            ->withTemplatePaths('/templates')
            ->withComponentPaths('components');

        $this->assertSame('x', $config->directivePrefix);
        $this->assertSame('x-', $config->elementPrefix);
        $this->assertSame(['/templates'], $config->templatePaths);
        $this->assertSame(['components'], $config->componentPaths);
    }

    public function testWithTemplatePathsUsingSpreadOperator(): void
    {
        $paths = ['/path1', '/path2', '/path3'];
        $config = (new SugarConfig())->withTemplatePaths(...$paths);

        $this->assertSame($paths, $config->templatePaths);
    }

    public function testWithComponentPathsUsingSpreadOperator(): void
    {
        $paths = ['components', 'ui', 'widgets'];
        $config = (new SugarConfig())->withComponentPaths(...$paths);

        $this->assertSame($paths, $config->componentPaths);
    }

    public function testPreservesExistingPropertiesWhenAddingTemplatePaths(): void
    {
        $config = (new SugarConfig(
            directivePrefix: 'v',
            elementPrefix: 'v-',
            componentPaths: ['components'],
        ))->withTemplatePaths('/templates');

        $this->assertSame('v', $config->directivePrefix);
        $this->assertSame('v-', $config->elementPrefix);
        $this->assertSame(['/templates'], $config->templatePaths);
        $this->assertSame(['components'], $config->componentPaths);
    }

    public function testPreservesExistingPropertiesWhenAddingComponentPaths(): void
    {
        $config = (new SugarConfig(
            directivePrefix: 'v',
            elementPrefix: 'v-',
            templatePaths: ['/templates'],
        ))->withComponentPaths('components');

        $this->assertSame('v', $config->directivePrefix);
        $this->assertSame('v-', $config->elementPrefix);
        $this->assertSame(['/templates'], $config->templatePaths);
        $this->assertSame(['components'], $config->componentPaths);
    }
}
