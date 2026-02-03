<?php
declare(strict_types=1);

namespace Sugar\Test\Integration\Config;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Tests\ExecuteTemplateTrait;

final class CustomPrefixIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;

    public function testCustomPrefixIfDirective(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        $template = '<div x:if="$show">Hello</div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);
        $this->assertStringContainsString('<div>Hello</div>', $result);

        $result = $this->executeTemplate($compiled, ['show' => false]);
        $this->assertStringNotContainsString('Hello', $result);
    }

    public function testCustomPrefixForeachDirective(): void
    {
        $config = SugarConfig::withPrefix('v');
        $compiler = $this->createCompiler($config);

        $template = '<li v:foreach="$items as $item"><?= $item ?></li>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('<li>a</li>', $result);
        $this->assertStringContainsString('<li>b</li>', $result);
        $this->assertStringContainsString('<li>c</li>', $result);
    }

    public function testCustomFragmentElement(): void
    {
        $config = new SugarConfig(
            directivePrefix: 'tw',
            fragmentElement: 'tw-fragment',
        );
        $compiler = $this->createCompiler($config);

        $template = '<tw-fragment tw:foreach="$items as $item"><li><?= $item ?></li></tw-fragment>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['items' => [1, 2, 3]]);

        // Should render children without wrapper
        $this->assertStringContainsString('<li>1</li>', $result);
        $this->assertStringContainsString('<li>2</li>', $result);
        $this->assertStringNotContainsString('tw-fragment', $result);
    }

    public function testOldPrefixIgnoredWithCustomConfig(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        // Old s:if should be treated as regular attribute
        $template = '<div s:if="$show">Hello</div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, ['show' => true]);

        // Should render with s:if as regular attribute
        $this->assertStringContainsString('s:if', $result);
    }

    public function testMixedDirectivesWithCustomPrefix(): void
    {
        $config = SugarConfig::withPrefix('x');
        $compiler = $this->createCompiler($config);

        $template = '<div x:if="$show" x:text="$message"></div>';
        $compiled = $compiler->compile($template);

        $result = $this->executeTemplate($compiled, [
            'show' => true,
            'message' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('<div>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    private function createCompiler(SugarConfig $config): Compiler
    {
        return new Compiler(
            parser: new Parser($config),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
            config: $config,
        );
    }
}
