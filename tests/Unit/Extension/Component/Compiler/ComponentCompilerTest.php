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
use Sugar\Extension\Component\Compiler\ComponentCompiler;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Loader\ComponentLoader;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

final class ComponentCompilerTest extends TestCase
{
    use ExecuteTemplateTrait;

    public function testCompileComponentThrowsWhenComponentMissing(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader();
        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: $config,
        );
        $compiler = new Compiler(
            parser: new Parser($config),
            escaper: new Escaper(),
            registry: new DirectiveRegistry(),
            templateLoader: $loader,
            config: $config,
        );

        $componentCompiler = new ComponentCompiler(
            compiler: $compiler,
            loader: $componentLoader,
        );

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "button" not found');

        $componentCompiler->compileComponent('button');
    }

    public function testCompileComponentMarksSlotVariablesAsRawViaInlinePasses(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            templates: ['components/s-button.sugar.php' => '<button><?= $slot ?></button>'],
        );
        $componentLoader = new ComponentLoader(
            templateLoader: $loader,
            config: $config,
        );

        $compiler = new Compiler(
            parser: new Parser($config),
            escaper: new Escaper(),
            registry: new DirectiveRegistry(),
            templateLoader: $loader,
            config: $config,
        );

        $componentCompiler = new ComponentCompiler(
            compiler: $compiler,
            loader: $componentLoader,
        );

        $tracker = new DependencyTracker();

        $componentCompiler->compileComponent('button', ['slot'], tracker: $tracker);

        $this->assertContains(
            $componentLoader->getComponentFilePath('button'),
            $tracker->getMetadata($componentLoader->getComponentPath('button'))->components,
        );
    }
}
