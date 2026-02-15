<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

use Sugar\Core\Ast\Interface\SiblingNavigationInterface;
use Sugar\Core\Ast\Trait\SiblingNavigationTrait;

/**
 * Structural directive node (if, foreach, while, etc.)
 *
 * Represents control flow directives using s:* attributes:
 * - s:if / s:elseif / s:else - Conditional rendering
 * - s:foreach / s:each - Loop iteration
 * - s:while - While loops
 */
final class DirectiveNode extends Node implements SiblingNavigationInterface
{
    use SiblingNavigationTrait;

    /**
     * Paired sibling directive (e.g., s:empty for s:forelse)
     */
    private ?DirectiveNode $pairedSibling = null;

    /**
     * Whether this directive has been consumed by a pairing primary directive
     */
    private bool $consumedByPairing = false;

    /**
     * Element metadata for directives that need to wrap the element itself
     */
    private ?ElementNode $elementNode = null;

    /**
     * @param string $name Directive name (e.g., 'if', 'foreach', 'while')
     * @param string $expression PHP expression for the directive
     * @param array<\Sugar\Core\Ast\Node> $children Child nodes to render when condition is true
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $name,
        public string $expression,
        public array $children,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    /**
     * Set element node metadata (for directives that need to wrap the element)
     */
    public function setElementNode(ElementNode $element): void
    {
        $this->elementNode = $element;
    }

    /**
     * Get element node metadata
     */
    public function getElementNode(): ?ElementNode
    {
        return $this->elementNode;
    }

    /**
     * Set paired sibling directive node
     */
    public function setPairedSibling(DirectiveNode $sibling): void
    {
        $this->pairedSibling = $sibling;
    }

    /**
     * Get paired sibling directive node
     */
    public function getPairedSibling(): ?DirectiveNode
    {
        return $this->pairedSibling;
    }

    /**
     * Mark this directive as consumed by a pairing primary directive
     */
    public function markConsumedByPairing(): void
    {
        $this->consumedByPairing = true;
    }

    /**
     * Check if this directive has been consumed by pairing
     */
    public function isConsumedByPairing(): bool
    {
        return $this->consumedByPairing;
    }

    /**
     * Get next sibling of given child node
     */
    public function getNextSibling(Node $child): ?Node
    {
        $index = array_search($child, $this->children, true);

        if (!is_int($index) || $index >= count($this->children) - 1) {
            return null;
        }

        return $this->children[$index + 1];
    }

    /**
     * Get previous sibling of given child node
     */
    public function getPreviousSibling(Node $child): ?Node
    {
        $index = array_search($child, $this->children, true);

        if (!is_int($index) || $index === 0) {
            return null;
        }

        return $this->children[$index - 1];
    }

    /**
     * Find next sibling matching predicate
     *
     * @param callable(\Sugar\Core\Ast\Node): bool $predicate
     */
    public function findNextSibling(Node $child, callable $predicate): ?Node
    {
        $index = array_search($child, $this->children, true);

        if (!is_int($index)) {
            return null;
        }

        $childCount = count($this->children);
        for ($i = $index + 1; $i < $childCount; $i++) {
            if ($predicate($this->children[$i])) {
                return $this->children[$i];
            }
        }

        return null;
    }
}
