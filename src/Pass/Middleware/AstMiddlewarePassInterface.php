<?php
declare(strict_types=1);

namespace Sugar\Pass\Middleware;

use Sugar\Ast\Node;

/**
 * Middleware hooks for a single AST traversal.
 */
interface AstMiddlewarePassInterface
{
    /**
     * Hook executed before child traversal.
     */
    public function before(Node $node, WalkContext $context): NodeAction;

    /**
     * Hook executed after child traversal.
     */
    public function after(Node $node, WalkContext $context): NodeAction;
}
