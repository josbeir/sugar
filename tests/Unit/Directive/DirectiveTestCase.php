<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ElementNode;
use Sugar\Ast\TextNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Tests\Helper\Trait\AstAssertionsTrait;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\CustomConstraintsTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

/**
 * Base class for directive compiler tests
 *
 * Provides common setup and helper methods for testing directive compilers
 */
abstract class DirectiveTestCase extends TestCase
{
    use AstAssertionsTrait;
    use CompilerTestTrait;
    use CustomConstraintsTrait;
    use NodeBuildersTrait;

    protected DirectiveInterface $directiveCompiler;

    /**
     * Get the directive compiler instance to test
     */
    abstract protected function getDirectiveCompiler(): DirectiveInterface;

    /**
     * Get the directive name (e.g., 'if', 'foreach')
     */
    abstract protected function getDirectiveName(): string;

    protected function setUp(): void
    {
        $this->setUpCompiler();
        $this->directiveCompiler = $this->getDirectiveCompiler();
    }

    /**
     * Create a test element node with directive attribute
     *
     * @param array<\Sugar\Ast\Node> $children
     */
    protected function createDirectiveElement(
        string $expression,
        string $tagName = 'div',
        array $children = [],
    ): ElementNode {
        return $this->element($tagName)
            ->attribute('s:' . $this->getDirectiveName(), $expression)
            ->withChildren($children)
            ->at(1, 1)
            ->build();
    }

    /**
     * Create a text node
     */
    protected function createTextNode(string $content): TextNode
    {
        return $this->text($content, 1, 1);
    }

    /**
     * Create a compilation context for testing
     */
    protected function createTestContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext(
            templatePath: $templatePath,
            source: $source,
            debug: $debug,
        );
    }

    /**
     * Assert compiled output contains PHP code
     */
    protected function assertContainsPhp(string $expectedPhp, string $actual): void
    {
        $this->assertStringContainsString($expectedPhp, $actual);
    }

    /**
     * Assert compiled output is valid PHP
     */
    protected function assertValidPhp(string $code): void
    {
        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('declare(strict_types=1);', $code);
    }
}
