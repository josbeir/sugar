<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Assertion;

use PHPUnit\Framework\Assert;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;

/**
 * Fluent builder for AST assertions
 *
 * Provides chainable methods for asserting AST structure and content
 */
final class AstAssertionBuilder
{
    /**
     * @param array<\Sugar\Ast\Node>|DocumentNode $ast
     */
    public function __construct(
        private readonly array|DocumentNode $ast,
    ) {
    }

    /**
     * Assert the AST has a specific number of nodes
     */
    public function hasCount(int $expected): self
    {
        $actual = is_array($this->ast) ? count($this->ast) : count($this->ast->children);
        Assert::assertSame($expected, $actual, sprintf('Expected %d nodes, got %d', $expected, $actual));

        return $this;
    }

    /**
     * Assert the AST has more than a specific number of nodes
     */
    public function hasCountGreaterThan(int $minimum): self
    {
        $actual = is_array($this->ast) ? count($this->ast) : count($this->ast->children);
        Assert::assertGreaterThan($minimum, $actual, sprintf('Expected more than %d nodes, got %d', $minimum, $actual));

        return $this;
    }

    /**
     * Assert the AST contains at least one node of the given type
     */
    public function containsNodeType(string $class): self
    {
        $nodes = $this->getNodes();
        $found = false;

        foreach ($nodes as $node) {
            if ($node instanceof $class) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf('No node of type %s found in AST', $class));

        return $this;
    }

    /**
     * Assert the AST contains a RawPhpNode with specific code
     */
    public function hasPhpCode(string $expectedCode): self
    {
        $nodes = $this->getNodes();
        $found = false;

        foreach ($nodes as $node) {
            if ($node instanceof RawPhpNode && str_contains($node->code, $expectedCode)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf("PHP code '%s' not found in AST", $expectedCode));

        return $this;
    }

    /**
     * Assert the AST contains an element with the given tag name
     */
    public function containsElement(string $tagName): ElementAssertionBuilder
    {
        $element = $this->findElement($tagName);
        Assert::assertNotNull($element, sprintf("Element '%s' not found in AST", $tagName));

        return new ElementAssertionBuilder($element, $this);
    }

    /**
     * Assert the AST does NOT contain an element with the given tag name
     */
    public function doesNotContainElement(string $tagName): self
    {
        $element = $this->findElement($tagName);
        Assert::assertNull($element, sprintf("Element '%s' should not exist in AST", $tagName));

        return $this;
    }

    /**
     * Assert the AST contains a directive with the given name
     */
    public function containsDirective(string $name): DirectiveAssertionBuilder
    {
        $directive = $this->findDirective($name);
        Assert::assertNotNull($directive, sprintf("Directive '%s' not found in AST", $name));

        return new DirectiveAssertionBuilder($directive, $this);
    }

    /**
     * Assert the AST contains a TextNode with specific content
     */
    public function containsText(string $content): self
    {
        $nodes = $this->getNodes();
        $found = false;

        foreach ($nodes as $node) {
            if ($node instanceof TextNode && str_contains($node->content, $content)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf("Text '%s' not found in AST", $content));

        return $this;
    }

    /**
     * Assert the AST contains an OutputNode
     */
    public function containsOutput(): self
    {
        return $this->containsNodeType(OutputNode::class);
    }

    /**
     * Assert the AST is empty
     */
    public function isEmpty(): self
    {
        return $this->hasCount(0);
    }

    /**
     * Assert the AST is not empty
     */
    public function isNotEmpty(): self
    {
        $actual = is_array($this->ast) ? count($this->ast) : count($this->ast->children);
        Assert::assertGreaterThan(0, $actual, 'Expected AST to not be empty');

        return $this;
    }

    /**
     * Get all nodes as array
     *
     * @return array<\Sugar\Ast\Node>
     */
    private function getNodes(): array
    {
        return is_array($this->ast) ? $this->ast : $this->ast->children;
    }

    /**
     * Find an element by tag name (recursive search)
     */
    private function findElement(string $tagName): ?ElementNode
    {
        $nodes = $this->getNodes();

        foreach ($nodes as $node) {
            if ($node instanceof ElementNode && $node->tag === $tagName) {
                return $node;
            }

            // Search recursively in children
            if ($node instanceof ElementNode || $node instanceof DirectiveNode) {
                $found = $this->findElementInChildren($node->children, $tagName);
                if ($found instanceof ElementNode) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find element in children recursively
     *
     * @param array<\Sugar\Ast\Node> $children
     */
    private function findElementInChildren(array $children, string $tagName): ?ElementNode
    {
        foreach ($children as $child) {
            if ($child instanceof ElementNode && $child->tag === $tagName) {
                return $child;
            }

            if ($child instanceof ElementNode || $child instanceof DirectiveNode) {
                $found = $this->findElementInChildren($child->children, $tagName);
                if ($found instanceof ElementNode) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find a directive by name (recursive search)
     */
    private function findDirective(string $name): ?DirectiveNode
    {
        $nodes = $this->getNodes();

        foreach ($nodes as $node) {
            if ($node instanceof DirectiveNode && $node->name === $name) {
                return $node;
            }

            // Search recursively in children
            if ($node instanceof ElementNode || $node instanceof DirectiveNode) {
                $found = $this->findDirectiveInChildren($node->children, $name);
                if ($found instanceof DirectiveNode) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find directive in children recursively
     *
     * @param array<\Sugar\Ast\Node> $children
     */
    private function findDirectiveInChildren(array $children, string $name): ?DirectiveNode
    {
        foreach ($children as $child) {
            if ($child instanceof DirectiveNode && $child->name === $name) {
                return $child;
            }

            if ($child instanceof ElementNode || $child instanceof DirectiveNode) {
                $found = $this->findDirectiveInChildren($child->children, $name);
                if ($found instanceof DirectiveNode) {
                    return $found;
                }
            }
        }

        return null;
    }
}
