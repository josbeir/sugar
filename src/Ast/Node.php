<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Base node for all AST elements
 */
abstract class Node
{
    /**
     * Parent node in the AST tree
     */
    private ?Node $parent = null;

    /**
     * Constructor
     *
     * @param int $line Line number
     * @param int $column Column number
     */
    public function __construct(
        public readonly int $line,
        public readonly int $column,
    ) {
    }

    /**
     * Set parent node
     */
    public function setParent(?Node $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Get parent node
     */
    public function getParent(): ?Node
    {
        return $this->parent;
    }
}
