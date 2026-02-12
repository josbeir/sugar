<?php
declare(strict_types=1);

namespace Sugar\Directive;

use LogicException;
use Sugar\Ast\Node;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\ContentWrappingDirectiveInterface;
use Sugar\Enum\DirectiveType;

/**
 * Content wrapping modifier for s:nowrap.
 */
final class NoWrapDirective implements ContentWrappingDirectiveInterface
{
    /**
     * @inheritDoc
     */
    public function shouldWrapContentElement(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::PASS_THROUGH;
    }

    /**
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        throw new LogicException('s:nowrap should be handled during directive extraction.');
    }
}
