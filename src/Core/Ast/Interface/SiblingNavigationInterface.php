<?php
declare(strict_types=1);

namespace Sugar\Core\Ast\Interface;

use Sugar\Core\Ast\Node;

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
     * @param \Sugar\Core\Ast\Node $child The child node to find the next sibling of
     * @return \Sugar\Core\Ast\Node|null Next sibling or null if none exists
     */
    public function getNextSibling(Node $child): ?Node;

    /**
     * Get previous sibling of given child node
     *
     * @param \Sugar\Core\Ast\Node $child The child node to find the previous sibling of
     * @return \Sugar\Core\Ast\Node|null Previous sibling or null if none exists
     */
    public function getPreviousSibling(Node $child): ?Node;

    /**
     * Find next sibling matching predicate
     *
     * @param \Sugar\Core\Ast\Node $child The child node to start searching from
     * @param callable(\Sugar\Core\Ast\Node): bool $predicate Predicate to match siblings against
     * @return \Sugar\Core\Ast\Node|null First matching sibling or null if none found
     */
    public function findNextSibling(Node $child, callable $predicate): ?Node;
}
