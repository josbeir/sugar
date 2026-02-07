<?php
declare(strict_types=1);

namespace Sugar\Compiler\Pipeline;

use Sugar\Ast\Node;

/**
 * Compiler hooks for a single AST traversal.
 */
interface AstPassInterface
{
    /**
     * Hook executed before child traversal.
     */
    public function before(Node $node, PipelineContext $context): NodeAction;

    /**
     * Hook executed after child traversal.
     */
    public function after(Node $node, PipelineContext $context): NodeAction;
}
