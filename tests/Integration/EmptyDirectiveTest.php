<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use ArrayIterator;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Tests\ExecuteTemplateTrait;

/**
 * Integration tests for s:empty and s:forelse directives with various data types
 *
 * Tests that EmptyHelper correctly handles arrays, Countable, Traversable, objects
 */
final class EmptyDirectiveTest extends TestCase
{
    use ExecuteTemplateTrait;

    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(
            parser: new Parser(),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
        );
    }

    // s:empty directive tests

    public function testEmptyDirectiveWithEmptyString(): void
    {
        $template = '<div s:empty="$value">Empty!</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => '']);

        $this->assertStringContainsString('<div>Empty!</div>', $output);
    }

    public function testEmptyDirectiveWithNonEmptyString(): void
    {
        $template = '<div s:empty="$value">Empty!</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => 'hello']);

        $this->assertStringNotContainsString('Empty!', $output);
    }

    public function testEmptyDirectiveWithEmptyArray(): void
    {
        $template = '<div s:empty="$items">No items</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => []]);

        $this->assertStringContainsString('<div>No items</div>', $output);
    }

    public function testEmptyDirectiveWithNonEmptyArray(): void
    {
        $template = '<div s:empty="$items">No items</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => [1, 2, 3]]);

        $this->assertStringNotContainsString('No items', $output);
    }

    public function testEmptyDirectiveWithEmptyArrayObject(): void
    {
        $template = '<div s:empty="$collection">Empty collection</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['collection' => new ArrayObject([])]);

        $this->assertStringContainsString('<div>Empty collection</div>', $output);
    }

    public function testEmptyDirectiveWithNonEmptyArrayObject(): void
    {
        $template = '<div s:empty="$collection">Empty collection</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['collection' => new ArrayObject([1, 2, 3])]);

        $this->assertStringNotContainsString('Empty collection', $output);
    }

    public function testEmptyDirectiveWithObject(): void
    {
        $template = '<div s:empty="$user">Guest</div>';
        $compiled = $this->compiler->compile($template);

        $user = new class {
            public string $name = 'John';
        };
        $output = $this->executeTemplate($compiled, ['user' => $user]);

        // Objects are never empty
        $this->assertStringNotContainsString('Guest', $output);
    }

    public function testEmptyDirectiveWithNull(): void
    {
        $template = '<div s:empty="$value">Null value</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => null]);

        $this->assertStringContainsString('<div>Null value</div>', $output);
    }

    public function testEmptyDirectiveWithZero(): void
    {
        $template = '<div s:empty="$value">Zero!</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => 0]);

        $this->assertStringContainsString('<div>Zero!</div>', $output);
    }

    // s:forelse directive tests

    public function testForelseWithEmptyArray(): void
    {
        $template = '<li s:forelse="$items as $item"><?= $item ?></li>' .
            '<div s:empty>No items found</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => []]);

        $this->assertStringContainsString('<div>No items found</div>', $output);
        $this->assertStringNotContainsString('<li>', $output);
    }

    public function testForelseWithNonEmptyArray(): void
    {
        $template = '<li s:forelse="$items as $item"><?= $item ?></li>' .
            '<div s:empty>No items found</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => ['A', 'B', 'C']]);

        $this->assertStringContainsString('<li>A</li>', $output);
        $this->assertStringContainsString('<li>B</li>', $output);
        $this->assertStringContainsString('<li>C</li>', $output);
        $this->assertStringNotContainsString('No items found', $output);
    }

    public function testForelseWithEmptyArrayObject(): void
    {
        $template = '<li s:forelse="$collection as $item"><?= $item ?></li>' .
            '<div s:empty>Empty collection</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['collection' => new ArrayObject([])]);

        $this->assertStringContainsString('<div>Empty collection</div>', $output);
        $this->assertStringNotContainsString('<li>', $output);
    }

    public function testForelseWithNonEmptyArrayObject(): void
    {
        $template = '<li s:forelse="$collection as $item"><?= $item ?></li>' .
            '<div s:empty>Empty collection</div>';
        $compiled = $this->compiler->compile($template);

        $collection = new ArrayObject(['X', 'Y', 'Z']);
        $output = $this->executeTemplate($compiled, ['collection' => $collection]);

        $this->assertStringContainsString('<li>X</li>', $output);
        $this->assertStringContainsString('<li>Y</li>', $output);
        $this->assertStringContainsString('<li>Z</li>', $output);
        $this->assertStringNotContainsString('Empty collection', $output);
    }

    public function testForelseWithEmptyArrayIterator(): void
    {
        $template = '<li s:forelse="$iterator as $item"><?= $item ?></li>' .
            '<div s:empty>No items</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['iterator' => new ArrayIterator([])]);

        $this->assertStringContainsString('<div>No items</div>', $output);
        $this->assertStringNotContainsString('<li>', $output);
    }

    public function testForelseWithNonEmptyArrayIterator(): void
    {
        $template = '<li s:forelse="$iterator as $item"><?= $item ?></li>' .
            '<div s:empty>No items</div>';
        $compiled = $this->compiler->compile($template);

        $iterator = new ArrayIterator([1, 2, 3]);
        $output = $this->executeTemplate($compiled, ['iterator' => $iterator]);

        $this->assertStringContainsString('<li>1</li>', $output);
        $this->assertStringContainsString('<li>2</li>', $output);
        $this->assertStringContainsString('<li>3</li>', $output);
        $this->assertStringNotContainsString('No items', $output);
    }

    public function testForelseWithEmptyGenerator(): void
    {
        $template = '<li s:forelse="$generator as $item"><?= $item ?></li>' .
            '<div s:empty>No items</div>';
        $compiled = $this->compiler->compile($template);

        $generator = (function () {
            if (false) { // @phpstan-ignore if.alwaysFalse (intentional - makes function a generator)
                yield;
            }
        })();

        $output = $this->executeTemplate($compiled, ['generator' => $generator]);

        $this->assertStringContainsString('<div>No items</div>', $output);
        $this->assertStringNotContainsString('<li>', $output);
    }

    public function testForelseWithNonEmptyGenerator(): void
    {
        $template = '<li s:forelse="$generator as $item"><?= $item ?></li>' .
            '<div s:empty>No items</div>';
        $compiled = $this->compiler->compile($template);

        $generator = (function () {
            yield 'First';
            yield 'Second';
        })();

        $output = $this->executeTemplate($compiled, ['generator' => $generator]);

        $this->assertStringContainsString('<li>First</li>', $output);
        $this->assertStringContainsString('<li>Second</li>', $output);
        $this->assertStringNotContainsString('No items', $output);
    }

    public function testEmptyDirectiveCompiledOutput(): void
    {
        $template = '<div s:empty="$items">Empty</div>';
        $compiled = $this->compiler->compile($template);

        // Should use EmptyHelper::isEmpty()
        $this->assertStringContainsString('\\Sugar\\Runtime\\EmptyHelper::isEmpty($items)', $compiled);
    }

    public function testForelseCompiledOutput(): void
    {
        $template = '<li s:forelse="$items as $item"><?= $item ?></li>' .
            '<div s:empty>Empty</div>';
        $compiled = $this->compiler->compile($template);

        // Should use EmptyHelper::isEmpty()
        $this->assertStringContainsString('\\Sugar\\Runtime\\EmptyHelper::isEmpty($items)', $compiled);
    }
}
