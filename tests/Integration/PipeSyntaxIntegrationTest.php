<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Tests\ExecuteTemplateTrait;

/**
 * Integration tests for pipe syntax
 */
final class PipeSyntaxIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;

    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(
            new Parser(),
            new ContextAnalysisPass(),
            new Escaper(),
        );
    }

    public function testSimplePipeExecution(): void
    {
        $template = '<?= $name |> strtoupper(...) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['name' => 'john']);

        $this->assertSame('JOHN', $result);
    }

    public function testMultiplePipesExecution(): void
    {
        $template = '<?= $name |> strtoupper(...) |> substr(..., 0, 3) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['name' => 'alexander']);

        $this->assertSame('ALE', $result);
    }

    public function testPipeWithMultipleArgumentsExecution(): void
    {
        $template = '<?= $value |> number_format(..., 2, ".", ",") ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['value' => 1234.567]);

        $this->assertSame('1,234.57', $result);
    }

    public function testPipeWithHtmlEscaping(): void
    {
        $template = '<?= $text |> strtoupper(...) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['text' => '<script>alert("xss")</script>']);

        // Should escape HTML entities after uppercase transformation
        $this->assertStringContainsString('&lt;SCRIPT&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testPipeWithRawFunction(): void
    {
        $template = '<?= raw($html |> strtoupper(...)) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['html' => '<b>bold</b>']);

        // Should NOT escape HTML when using raw()
        $this->assertSame('<B>BOLD</B>', $result);
    }

    public function testPipeInHtmlElement(): void
    {
        $template = '<h1><?= $title |> strtoupper(...) ?></h1>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['title' => 'hello world']);

        $this->assertSame('<h1>HELLO WORLD</h1>', $result);
    }

    public function testComplexPipeChain(): void
    {
        $template = '<?= $text |> trim(...) |> strtoupper(...) |> substr(..., 0, 5) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['text' => '  hello world  ']);

        $this->assertSame('HELLO', $result);
    }

    public function testPipeWithArrayFunction(): void
    {
        $template = '<?= $items |> implode(", ", ...) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['items' => ['apple', 'banana', 'cherry']]);

        $this->assertSame('apple, banana, cherry', $result);
    }

    public function testMultiplePipesInTemplate(): void
    {
        $template = '<?= $first |> strtoupper(...) ?> and <?= $second |> strtolower(...) ?>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, [
            'first' => 'hello',
            'second' => 'WORLD',
        ]);

        $this->assertSame('HELLO and world', $result);
    }

    public function testPipeWithSTextDirective(): void
    {
        $template = '<div s:text="$name |> strtoupper(...)"></div>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['name' => 'john']);

        $this->assertSame('<div>JOHN</div>', $result);
    }

    public function testPipeWithSTextDirectiveEscapesHtml(): void
    {
        $template = '<div s:text="$text |> strtoupper(...)"></div>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['text' => '<script>xss</script>']);

        // Should escape HTML after transformation
        $this->assertStringContainsString('&lt;SCRIPT&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testPipeWithSHtmlDirective(): void
    {
        $template = '<div s:html="$html |> strtoupper(...)"></div>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['html' => '<b>bold</b>']);

        // s:html should NOT escape
        $this->assertSame('<div><B>BOLD</B></div>', $result);
    }

    public function testPipeWithSTextAndOtherDirectives(): void
    {
        $template = '<div s:if="$show" s:text="$name |> strtoupper(...) |> substr(..., 0, 3)"></div>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, [
            'show' => true,
            'name' => 'alexander',
        ]);

        $this->assertSame('<div>ALE</div>', $result);
    }

    public function testPipeWithSTextInForeach(): void
    {
        $template = '<li s:foreach="$items as $item" s:text="$item |> strtoupper(...)"></li>';

        $compiled = $this->compiler->compile($template);
        $result = $this->executeTemplate($compiled, ['items' => ['apple', 'banana']]);

        $this->assertStringContainsString('<li>APPLE</li>', $result);
        $this->assertStringContainsString('<li>BANANA</li>', $result);
    }
}
