<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use LogicException;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;

/**
 * Generic pass-through compiler for pseudo-directives
 *
 * Some attributes look like directives (s:slot, s:bind, etc.) but are actually
 * handled by other compiler passes rather than the directive compilation system.
 * This compiler exists to register them in the directive system so
 * DirectiveExtractionPass knows to pass them through without processing.
 *
 * Used for:
 * - s:slot - handled by extension-specific passes
 * - s:bind - handled by extension-specific passes
 * - Future pseudo-directives that need similar treatment
 */
final class PassThroughDirective implements DirectiveInterface
{
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
        // This should never be called - PASS_THROUGH directives are filtered in DirectiveExtractionPass
        throw new LogicException(
            'Pass-through directives should not be compiled. ' .
            'They are registered only for type checking and are handled by other compiler passes.',
        );
    }
}
