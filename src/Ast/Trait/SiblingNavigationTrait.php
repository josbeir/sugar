<?php
declare(strict_types=1);

namespace Sugar\Ast\Trait;

use Sugar\Ast\Node;

/**
 * Provides sibling navigation methods for nodes with children
 *
 * Requires the using class to have a public array $children property.
 * Implements SiblingNavigationInterface.
 */
trait SiblingNavigationTrait
{
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
     * @param callable(\Sugar\Ast\Node): bool $predicate
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
