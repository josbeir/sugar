<?php
declare(strict_types=1);

namespace Sugar\Tests\Builder;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\Node;

/**
 * Fluent builder for creating DocumentNode instances in tests
 */
final class DocumentNodeBuilder
{
    /**
     * @var array<Node>
     */
    private array $children = [];

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
     * Build the DocumentNode
     */
    public function build(): DocumentNode
    {
        return new DocumentNode($this->children);
    }
}
