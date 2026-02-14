<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Sugar\Runtime\EmptyHelper;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Integration tests for s:notempty directive with various data types
 *
 * Mirrors s:empty edge cases to ensure negated behavior stays consistent.
 */
final class NotEmptyDirectiveTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testNotEmptyDirectiveWithNonEmptyString(): void
    {
        $template = '<div s:notempty="$value">Has content</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => 'hello']);

        $this->assertStringContainsString('<div>Has content</div>', $output);
    }

    public function testNotEmptyDirectiveWithEmptyString(): void
    {
        $template = '<div s:notempty="$value">Has content</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => '']);

        $this->assertStringNotContainsString('Has content', $output);
    }

    public function testNotEmptyDirectiveWithEmptyArray(): void
    {
        $template = '<div s:notempty="$items">Has items</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => []]);

        $this->assertStringNotContainsString('Has items', $output);
    }

    public function testNotEmptyDirectiveWithNonEmptyArray(): void
    {
        $template = '<div s:notempty="$items">Has items</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => [1, 2, 3]]);

        $this->assertStringContainsString('<div>Has items</div>', $output);
    }

    public function testNotEmptyDirectiveWithEmptyArrayObject(): void
    {
        $template = '<div s:notempty="$collection">Has collection</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['collection' => new ArrayObject([])]);

        $this->assertStringNotContainsString('Has collection', $output);
    }

    public function testNotEmptyDirectiveWithNonEmptyArrayObject(): void
    {
        $template = '<div s:notempty="$collection">Has collection</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['collection' => new ArrayObject([1, 2, 3])]);

        $this->assertStringContainsString('<div>Has collection</div>', $output);
    }

    public function testNotEmptyDirectiveWithObject(): void
    {
        $template = '<div s:notempty="$user">Logged in</div>';
        $compiled = $this->compiler->compile($template);

        $user = new class {
            public string $name = 'John';
        };
        $output = $this->executeTemplate($compiled, ['user' => $user]);

        $this->assertStringContainsString('<div>Logged in</div>', $output);
    }

    public function testNotEmptyDirectiveWithNull(): void
    {
        $template = '<div s:notempty="$value">Has value</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => null]);

        $this->assertStringNotContainsString('Has value', $output);
    }

    public function testNotEmptyDirectiveWithZero(): void
    {
        $template = '<div s:notempty="$value">Has value</div>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['value' => 0]);

        $this->assertStringNotContainsString('Has value', $output);
    }

    public function testNotEmptyDirectiveCompiledOutput(): void
    {
        $template = '<div s:notempty="$items">Has items</div>';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('!' . EmptyHelper::class . '::isEmpty($items)', $compiled);
    }
}
