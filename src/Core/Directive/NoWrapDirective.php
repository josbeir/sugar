<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use LogicException;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\ContentWrappingDirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

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
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        throw new LogicException('s:nowrap should be handled during directive extraction.');
    }
}
