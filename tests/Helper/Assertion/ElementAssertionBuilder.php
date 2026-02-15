<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Assertion;

use PHPUnit\Framework\Assert;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\TextNode;

/**
 * Fluent builder for element-specific assertions
 */
final class ElementAssertionBuilder
{
    public function __construct(
        private readonly ElementNode $element,
        private readonly AstAssertionBuilder $parent,
    ) {
    }

    /**
     * Assert the element has a specific attribute
     */
    public function withAttribute(string $name, ?string $value = null): self
    {
        $found = false;

        foreach ($this->element->attributes as $attr) {
            if ($attr->name !== $name) {
                continue;
            }

            if ($value !== null && (!$attr->value->isStatic() || $attr->value->static !== $value)) {
                continue;
            }

            $found = true;
            break;
        }

        if ($value === null) {
            Assert::assertTrue($found, sprintf("Attribute '%s' not found on element", $name));
        } else {
            Assert::assertTrue($found, sprintf("Attribute '%s' with value '%s' not found on element", $name, $value));
        }

        return $this;
    }

    /**
     * Assert the element has a specific class
     */
    public function withClass(string $className): self
    {
        return $this->withAttribute('class', $className);
    }

    /**
     * Assert the element has a specific ID
     */
    public function withId(string $id): self
    {
        return $this->withAttribute('id', $id);
    }

    /**
     * Assert the element has a specific number of attributes
     */
    public function hasAttributeCount(int $count): self
    {
        Assert::assertCount($count, $this->element->attributes);

        return $this;
    }

    /**
     * Assert the element has a specific number of children
     */
    public function hasChildCount(int $count): self
    {
        Assert::assertCount($count, $this->element->children);

        return $this;
    }

    /**
     * Assert the element contains a text child with specific content
     */
    public function containsText(string $content): self
    {
        $found = false;

        foreach ($this->element->children as $child) {
            if ($child instanceof TextNode && str_contains($child->content, $content)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf("Text '%s' not found in element children", $content));

        return $this;
    }

    /**
     * Assert the element is self-closing
     */
    public function isSelfClosing(): self
    {
        Assert::assertTrue($this->element->selfClosing, 'Element should be self-closing');

        return $this;
    }

    /**
     * Assert the element is NOT self-closing
     */
    public function isNotSelfClosing(): self
    {
        Assert::assertFalse($this->element->selfClosing, 'Element should not be self-closing');

        return $this;
    }

    /**
     * Return to parent AST assertion builder
     */
    public function and(): AstAssertionBuilder
    {
        return $this->parent;
    }
}
