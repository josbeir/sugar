<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;

/**
 * Context for compiler traversal.
 */
final readonly class PipelineContext
{
    /**
     * @param \Sugar\Core\Compiler\CompilationContext $compilation Compilation context for errors
     * @param \Sugar\Core\Ast\Node|null $parent Parent node in the AST
     * @param int $indexInParent Index within the parent's children array
     */
    public function __construct(
        public CompilationContext $compilation,
        public ?Node $parent,
        public int $indexInParent,
    ) {
    }
}
