<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\Helper\ExpressionValidator;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ExpressionValidatorTest extends TestCase
{
    use TemplateTestHelperTrait;

    /**
     * Test that valid array expressions pass validation
     *
     * @param string $expression Valid array expression
     */
    #[DataProvider('validArrayExpressionsProvider')]
    public function testValidatesValidArrayExpressions(string $expression): void
    {
        // Should not throw
        ExpressionValidator::validateArrayExpression($expression, 's:bind attribute');
        // If we get here without exception, test passes
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test that isPotentiallyArray returns true for valid expressions
     *
     * @param string $expression Valid array expression
     */
    #[DataProvider('validArrayExpressionsProvider')]
    public function testIsPotentiallyArrayReturnsTrueForValidExpressions(string $expression): void
    {
        $this->assertTrue(ExpressionValidator::isPotentiallyArray($expression));
    }

    /**
     * Provide valid array expressions
     *
     * @return \Iterator<string, array{string}>
     */
    public static function validArrayExpressionsProvider(): Iterator
    {
        // Array literals
        yield 'empty array' => ['[]'];
        yield 'short array syntax' => ["['key' => 'value']"];
        yield 'multi-item array' => ["['title' => 'Hello', 'type' => 'info']"];
        yield 'nested array' => ["['data' => ['nested' => 'value']]"];
        yield 'array with spaces' => ["  ['key' => 'value']  "];
        yield 'long array syntax' => ["array('key' => 'value')"];
        yield 'array function' => ['array()'];
        // Variables
        yield 'simple variable' => ['$bindings'];
        yield 'variable with underscore' => ['$my_bindings'];
        yield 'camelCase variable' => ['$myBindings'];
        yield 'this property' => ['$this->bindings'];
        yield 'nested property' => ['$this->component->bindings'];
        yield 'array access' => ['$data["bindings"]'];
        yield 'variable with spaces' => ['  $bindings  '];
        // Function calls
        yield 'function call' => ['getBindings()'];
        yield 'function with args' => ['getBindings($id)'];
        yield 'namespaced function' => ['\\App\\getBindings()'];
        yield 'method call' => ['$obj->getBindings()'];
        yield 'static method' => ['MyClass::getBindings()'];
        yield 'static self' => ['self::getBindings()'];
        yield 'static parent' => ['parent::getBindings()'];
        // Ternary & null coalescing
        yield 'null coalescing' => ['$bindings ?? []'];
        yield 'null coalescing chain' => ['$a ?? $b ?? []'];
        yield 'ternary' => ['$condition ? [] : []'];
        yield 'ternary with arrays' => ['$x ? ["a" => 1] : ["b" => 2]'];
        yield 'nested ternary' => ['$a ? $b : ($c ? [] : [])'];
        // Complex expressions
        yield 'combined' => ['$bindings ?? getDefault()'];
        yield 'function with ternary' => ['getValue() ? [] : getOther()'];
    }

    /**
     * Test that invalid expressions throw SyntaxException
     *
     * @param string $expression Invalid expression
     * @param string $expectedType Expected type in error message
     */
    #[DataProvider('invalidArrayExpressionsProvider')]
    public function testThrowsForInvalidArrayExpressions(string $expression, string $expectedType): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage($expectedType);

        ExpressionValidator::validateArrayExpression($expression, 's:bind attribute');
    }

    /**
     * Test that isPotentiallyArray returns false for invalid expressions
     *
     * @param string $expression Invalid expression
     * @param string $expectedType Expected type in error message (unused here)
     */
    #[DataProvider('invalidArrayExpressionsProvider')]
    public function testIsPotentiallyArrayReturnsFalseForInvalidExpressions(string $expression, string $expectedType): void
    {
        $this->assertFalse(ExpressionValidator::isPotentiallyArray($expression));
    }

    /**
     * Provide invalid array expressions
     *
     * @return \Iterator<string, array{string, string}>
     */
    public static function invalidArrayExpressionsProvider(): Iterator
    {
        // String literals
        yield 'single quoted string' => ["'not an array'", 'string literal'];
        yield 'double quoted string' => ['"not an array"', 'string literal'];
        yield 'empty string single' => ["''", 'string literal'];
        yield 'empty string double' => ['""', 'string literal'];
        yield 'string with spaces' => ["  'hello'  ", 'string literal'];
        // Numbers
        yield 'integer' => ['123', 'number literal'];
        yield 'negative integer' => ['-456', 'number literal'];
        yield 'float' => ['45.67', 'number literal'];
        yield 'negative float' => ['-12.34', 'number literal'];
        yield 'zero' => ['0', 'number literal'];
        yield 'number with spaces' => ['  123  ', 'number literal'];
        // Booleans and null
        yield 'true lowercase' => ['true', 'true given'];
        yield 'true uppercase' => ['TRUE', 'TRUE given'];
        yield 'false lowercase' => ['false', 'false given'];
        yield 'false uppercase' => ['FALSE', 'FALSE given'];
        yield 'null lowercase' => ['null', 'null given'];
        yield 'null uppercase' => ['NULL', 'NULL given'];
        yield 'true with spaces' => ['  true  ', 'true given'];
    }

    /**
     * Test that context parameter is used in error message
     */
    public function testUsesContextInErrorMessage(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Custom context must be an array expression');

        ExpressionValidator::validateArrayExpression("'invalid'", 'custom context');
    }

    /**
     * Test that context is capitalized in error message
     */
    public function testCapitalizesContextInErrorMessage(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('S:bind attribute must be an array expression');

        ExpressionValidator::validateArrayExpression("'invalid'", 's:bind attribute');
    }

    /**
     * Test default context when not provided
     */
    public function testUsesDefaultContextWhenNotProvided(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Expression must be an array expression');

        ExpressionValidator::validateArrayExpression("'invalid'");
    }

    /**
     * Test edge cases
     */
    public function testHandlesEdgeCases(): void
    {
        // Whitespace-only expressions get checked (trimmed)
        $this->expectException(SyntaxException::class);
        ExpressionValidator::validateArrayExpression('  123  ', 'test');
    }

    /**
     * Test that context-aware exceptions include location metadata
     */
    public function testThrowsContextAwareExceptionWithLocation(): void
    {
        $template = '<s-button s:bind="\'not an array\'">Click</s-button>';
        $context = $this->createContext(
            source: $template,
            templatePath: 'test.sugar.php',
            debug: true,
        );

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must be an array expression');
        $this->expectExceptionMessage('string literal');

        // Line 1, column 20 points to the s:bind attribute
        ExpressionValidator::validateArrayExpression(
            "'not an array'",
            's:bind attribute',
            $context,
            1,
            20,
        );
    }
}
