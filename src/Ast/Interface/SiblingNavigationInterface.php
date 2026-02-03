<?php
declare(strict_types=1);

namespace Sugar\Ast\Interface;

use Sugar\Ast\Node;

/**
 * Interface for nodes that support sibling navigation
 *
 * Provides methods to traverse and query sibling nodes within a parent container.
 * Implemented by nodes with children arrays (DocumentNode, DirectiveNode, ElementNode).
 */
interface SiblingNavigationInterface
{
    /**
     * Get next sibling of given child node
     *
     * @param \Sugar\Ast\Node $child The child node to find the next sibling of
     * @return \Sugar\Ast\Node|null Next sibling or null if none exists
     */
    public function getNextSibling(Node $child): ?Node;

    /**
     * Get previous sibling of given child node
     *
     * @param \Sugar\Ast\Node $child The child node to find the previous sibling of
     * @return \Sugar\Ast\Node|null Previous sibling or null if none exists
     */
    public function getPreviousSibling(Node $child): ?Node;

    /**
     * Find next sibling matching predicate
     *
     * @param \Sugar\Ast\Node $child The child node to start searching from
     * @param callable(\Sugar\Ast\Node): bool $predicate Predicate to match siblings against
     * @return \Sugar\Ast\Node|null First matching sibling or null if none found
     */
    public function findNextSibling(Node $child, callable $predicate): ?Node;
}
