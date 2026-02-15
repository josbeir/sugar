<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

/**
 * Compiler for s:finally directive
 *
 * This directive must be paired with a preceding s:try.
 */
final class FinallyDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        throw $context->createSyntaxExceptionForNode(
            's:finally must follow s:try',
            $node,
        );
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
