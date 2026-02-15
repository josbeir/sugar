<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Component\Compiler\ComponentTemplateCompiler;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

final class ComponentTemplateCompilerTest extends TestCase
{
    use ExecuteTemplateTrait;

    public function testCompileComponentThrowsWhenComponentMissing(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(config: $config);
        $compiler = new Compiler(
            parser: new Parser($config),
            escaper: new Escaper(),
            registry: new DirectiveRegistry(),
            templateLoader: $loader,
            config: $config,
        );

        $componentCompiler = new ComponentTemplateCompiler(
            compiler: $compiler,
            loader: $loader,
        );

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "button" not found');

        $componentCompiler->compileComponent('button');
    }

    public function testCompileComponentMarksSlotVariablesAsRawViaInlinePasses(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            config: $config,
            components: [
                'button' => '<button><?= $slot ?></button>',
            ],
        );

        $compiler = new Compiler(
            parser: new Parser($config),
            escaper: new Escaper(),
            registry: new DirectiveRegistry(),
            templateLoader: $loader,
            config: $config,
        );

        $componentCompiler = new ComponentTemplateCompiler(
            compiler: $compiler,
            loader: $loader,
        );

        $compiled = $componentCompiler->compileComponent('button', ['slot']);

        $output = $this->executeTemplate($compiled, [
            'slot' => '<strong>Click</strong>',
            '__sugar_attrs' => [],
        ]);

        $this->assertStringContainsString('<button><strong>Click</strong></button>', $output);
    }

    public function testCompileComponentTracksComponentDependency(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            config: $config,
            components: [
                'button' => '<button><?= $slot ?></button>',
            ],
        );

        $compiler = new Compiler(
            parser: new Parser($config),
            escaper: new Escaper(),
            registry: new DirectiveRegistry(),
            templateLoader: $loader,
            config: $config,
        );

        $componentCompiler = new ComponentTemplateCompiler(
            compiler: $compiler,
            loader: $loader,
        );

        $tracker = new DependencyTracker();

        $componentCompiler->compileComponent('button', ['slot'], tracker: $tracker);

        $this->assertContains($loader->getComponentFilePath('button'), $tracker->getMetadata($loader->getComponentPath('button'))->components);
    }
}
