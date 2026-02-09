<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;

/**
 * Compiler for s:finally directive
 *
 * This directive must be paired with a preceding s:try.
 */
final class FinallyDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        throw $context->createException(
            SyntaxException::class,
            's:finally must follow s:try',
            $node->line,
            $node->column,
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
