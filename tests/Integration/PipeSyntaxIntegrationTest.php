<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Tests\ExecuteTemplateTrait;

/**
 * Integration tests for pipe syntax
 */
final class PipeSyntaxIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;

    private Parser $parser;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->generator = new CodeGenerator(new Escaper());
    }

    public function testSimplePipeExecution(): void
    {
        $template = '<?= $name |> strtoupper(...) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['name' => 'john']);

        $this->assertSame('JOHN', $result);
    }

    public function testMultiplePipesExecution(): void
    {
        $template = '<?= $name |> strtoupper(...) |> substr(..., 0, 3) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['name' => 'alexander']);

        $this->assertSame('ALE', $result);
    }

    public function testPipeWithMultipleArgumentsExecution(): void
    {
        $template = '<?= $value |> number_format(..., 2, ".", ",") ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['value' => 1234.567]);

        $this->assertSame('1,234.57', $result);
    }

    public function testPipeWithHtmlEscaping(): void
    {
        $template = '<?= $text |> strtoupper(...) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['text' => '<script>alert("xss")</script>']);

        // Should escape HTML entities after uppercase transformation
        $this->assertStringContainsString('&lt;SCRIPT&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testPipeWithRawFunction(): void
    {
        $template = '<?= raw($html |> strtoupper(...)) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['html' => '<b>bold</b>']);

        // Should NOT escape HTML when using raw()
        $this->assertSame('<B>BOLD</B>', $result);
    }

    public function testPipeInHtmlElement(): void
    {
        $template = '<h1><?= $title |> strtoupper(...) ?></h1>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['title' => 'hello world']);

        $this->assertSame('<h1>HELLO WORLD</h1>', $result);
    }

    public function testComplexPipeChain(): void
    {
        $template = '<?= $text |> trim(...) |> strtoupper(...) |> substr(..., 0, 5) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['text' => '  hello world  ']);

        $this->assertSame('HELLO', $result);
    }

    public function testPipeWithArrayFunction(): void
    {
        $template = '<?= $items |> implode(", ", ...) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, ['items' => ['apple', 'banana', 'cherry']]);

        $this->assertSame('apple, banana, cherry', $result);
    }

    public function testMultiplePipesInTemplate(): void
    {
        $template = '<?= $first |> strtoupper(...) ?> and <?= $second |> strtolower(...) ?>';

        $ast = $this->parser->parse($template);
        $compiled = $this->generator->generate($ast);
        $result = $this->executeTemplate($compiled, [
            'first' => 'hello',
            'second' => 'WORLD',
        ]);

        $this->assertSame('HELLO and world', $result);
    }
}
