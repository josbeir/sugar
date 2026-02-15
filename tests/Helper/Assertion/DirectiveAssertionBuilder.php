<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Assertion;

use PHPUnit\Framework\Assert;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\TextNode;

/**
 * Fluent builder for directive-specific assertions
 */
final class DirectiveAssertionBuilder
{
    public function __construct(
        private readonly DirectiveNode $directive,
        private readonly AstAssertionBuilder $parent,
    ) {
    }

    /**
     * Assert the directive has a specific expression
     */
    public function withExpression(string $expression): self
    {
        Assert::assertSame($expression, $this->directive->expression, 'Directive expression mismatch');

        return $this;
    }

    /**
     * Assert the directive expression contains specific text
     */
    public function expressionContains(string $text): self
    {
        Assert::assertStringContainsString($text, $this->directive->expression, sprintf("Directive expression does not contain '%s'", $text));

        return $this;
    }

    /**
     * Assert the directive has a specific number of children
     */
    public function hasChildCount(int $count): self
    {
        Assert::assertCount($count, $this->directive->children, 'Directive child count mismatch');

        return $this;
    }

    /**
     * Assert the directive contains a text child with specific content
     */
    public function containsText(string $content): self
    {
        $found = false;

        foreach ($this->directive->children as $child) {
            if ($child instanceof TextNode && str_contains($child->content, $content)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf("Text '%s' not found in directive children", $content));

        return $this;
    }

    /**
     * Assert the directive has no children
     */
    public function hasNoChildren(): self
    {
        return $this->hasChildCount(0);
    }

    /**
     * Return to parent AST assertion builder
     */
    public function and(): AstAssertionBuilder
    {
        return $this->parent;
    }
}
