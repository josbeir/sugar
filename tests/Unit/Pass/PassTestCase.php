<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\TextNode;
use Sugar\Context\CompilationContext;
use Sugar\Pass\PassInterface;
use Sugar\Tests\Helper\Trait\AstAssertionsTrait;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\CustomConstraintsTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

/**
 * Base class for compilation pass tests
 *
 * Provides common setup and helper methods for testing compilation passes
 */
abstract class PassTestCase extends TestCase
{
    use AstAssertionsTrait;
    use CompilerTestTrait;
    use CustomConstraintsTrait;
    use NodeBuildersTrait;

    protected PassInterface $pass;

    /**
     * Get the pass instance to test
     */
    abstract protected function getPass(): PassInterface;

    protected function setUp(): void
    {
        $this->setUpCompiler();
        $this->pass = $this->getPass();
    }

    /**
     * Execute the pass on AST
     */
    protected function execute(DocumentNode $ast, ?CompilationContext $context = null): DocumentNode
    {
        return $this->pass->execute($ast, $context ?? $this->createTestContext());
    }

    /**
     * Create a document node
     *
     * @param array<\Sugar\Ast\Node> $children
     */
    protected function createDocument(array $children = []): DocumentNode
    {
        return new DocumentNode($children);
    }

    /**
     * Create an element node
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     * @param array<\Sugar\Ast\Node> $children
     */
    protected function createElement(
        string $tag,
        array $attributes = [],
        array $children = [],
        bool $isSelfClosing = false,
    ): ElementNode {
        return new ElementNode(
            $tag,
            $attributes,
            $children,
            $isSelfClosing,
            1,
            1,
        );
    }

    /**
     * Create a text node
     */
    protected function createText(string $content): TextNode
    {
        return new TextNode($content, 1, 1);
    }

    /**
     * Create a compilation context
     */
    protected function createTestContext(
        string $templatePath = 'test.sugar.php',
        string $source = '',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }

    /**
     * Assert AST contains element with tag
     */
    protected function assertContainsElement(string $tagName, DocumentNode $ast): void
    {
        $found = false;
        foreach ($ast->children as $child) {
            if ($child instanceof ElementNode && $child->tag === $tagName) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'AST does not contain element with tag: ' . $tagName);
    }

    /**
     * Assert AST does not contain element with tag
     */
    protected function assertNotContainsElement(string $tagName, DocumentNode $ast): void
    {
        foreach ($ast->children as $child) {
            if ($child instanceof ElementNode && $child->tag === $tagName) {
                $this->fail('AST contains element with tag: ' . $tagName);
            }
        }
    }

    /**
     * Find first element with tag in AST
     */
    protected function findElement(string $tagName, DocumentNode $ast): ?ElementNode
    {
        foreach ($ast->children as $child) {
            if ($child instanceof ElementNode && $child->tag === $tagName) {
                return $child;
            }
        }

        return null;
    }
}
