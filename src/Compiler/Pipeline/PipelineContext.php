<?php
declare(strict_types=1);

namespace Sugar\Compiler\Pipeline;

use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;

/**
 * Context for compiler traversal.
 */
final readonly class PipelineContext
{
    /**
     * @param \Sugar\Context\CompilationContext $compilation Compilation context for errors
     * @param \Sugar\Ast\Node|null $parent Parent node in the AST
     * @param int $indexInParent Index within the parent's children array
     */
    public function __construct(
        public CompilationContext $compilation,
        public ?Node $parent,
        public int $indexInParent,
    ) {
    }
}
