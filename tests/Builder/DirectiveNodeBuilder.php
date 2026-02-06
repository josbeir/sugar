<?php
declare(strict_types=1);

namespace Sugar\Tests\Builder;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;

/**
 * Fluent builder for creating DirectiveNode instances in tests
 */
final class DirectiveNodeBuilder
{
    private string $expression = '';

    /**
     * @var array<Node>
     */
    private array $children = [];

    private int $line = 1;

    private int $column = 0;

    public function __construct(
        private readonly string $name,
    ) {
    }

    /**
     * Set the directive expression
     */
    public function expression(string $expr): self
    {
        $this->expression = $expr;

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
     * Set the line and column position
     */
    public function at(int $line, int $column = 0): self
    {
        $this->line = $line;
        $this->column = $column;

        return $this;
    }

    /**
     * Build the DirectiveNode
     */
    public function build(): DirectiveNode
    {
        return new DirectiveNode(
            name: $this->name,
            expression: $this->expression,
            children: $this->children,
            line: $this->line,
            column: $this->column,
        );
    }
}
