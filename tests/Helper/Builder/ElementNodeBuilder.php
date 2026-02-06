<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Builder;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;

/**
 * Fluent builder for creating ElementNode instances in tests
 */
final class ElementNodeBuilder
{
    /**
     * @var array<AttributeNode>
     */
    private array $attributes = [];

    /**
     * @var array<Node>
     */
    private array $children = [];

    private bool $selfClosing = false;

    private int $line = 1;

    private int $column = 0;

    public function __construct(
        private readonly string $tag,
    ) {
    }

    /**
     * Add a class attribute
     */
    public function class(string $value): self
    {
        return $this->attribute('class', $value);
    }

    /**
     * Add an id attribute
     */
    public function id(string $value): self
    {
        return $this->attribute('id', $value);
    }

    /**
     * Add any attribute
     */
    public function attribute(string $name, string $value): self
    {
        $this->attributes[] = new AttributeNode($name, $value, $this->line, 0);

        return $this;
    }

    /**
     * Add multiple attributes at once
     *
     * @param array<string, string> $attrs
     */
    public function attributes(array $attrs): self
    {
        foreach ($attrs as $name => $value) {
            $this->attribute($name, $value);
        }

        return $this;
    }

    /**
     * Add a single child node
     */
    public function withChild(Node $child): self
    {
        $this->children[] = $child;

        return $this;
    }

    /**
     * Set all children at once
     *
     * @param array<Node> $children
     */
    public function withChildren(array $children): self
    {
        $this->children = $children;

        return $this;
    }

    /**
     * Mark element as self-closing
     */
    public function selfClosing(): self
    {
        $this->selfClosing = true;

        return $this;
    }

    /**
     * Set the line and column position
     */
    public function at(int $line, int $column = 0): self
    {
        $this->line = $line;
        $this->column = $column;

        return $this;
    }

    /**
     * Build the ElementNode
     */
    public function build(): ElementNode
    {
        return new ElementNode(
            tag: $this->tag,
            attributes: $this->attributes,
            children: $this->children,
            selfClosing: $this->selfClosing,
            line: $this->line,
            column: $this->column,
        );
    }
}
