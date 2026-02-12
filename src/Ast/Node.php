<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Base node for all AST elements
 */
abstract class Node
{
    /**
     * Template path where this node originated.
     */
    private ?string $templatePath = null;

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
     * Set the origin template path for this node.
     */
    public function setTemplatePath(?string $templatePath): void
    {
        $this->templatePath = $templatePath;
    }

    /**
     * Get the origin template path for this node.
     */
    public function getTemplatePath(): ?string
    {
        return $this->templatePath;
    }

    /**
     * Copy the template path from another node.
     */
    public function inheritTemplatePathFrom(Node $node): void
    {
        $this->templatePath = $node->getTemplatePath();
    }

    /**
     * Get parent node
     */
    public function getParent(): ?Node
    {
        return $this->parent;
    }
}
