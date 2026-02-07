<?php
declare(strict_types=1);

namespace Sugar\Directive;

use LogicException;
use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Enum\DirectiveType;

/**
 * Generic pass-through compiler for pseudo-directives
 *
 * Some attributes look like directives (s:slot, s:bind, etc.) but are actually
 * handled by other compiler passes rather than the directive compilation system.
 * This compiler exists to register them in the directive system so
 * DirectiveExtractionPass knows to pass them through without processing.
 *
 * Used for:
 * - s:slot - handled by ComponentExpansionPass
 * - s:bind - handled by ComponentExpansionPass
 * - Future pseudo-directives that need similar treatment
 */
final class PassThroughCompiler implements DirectiveCompilerInterface
{
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
        // This should never be called - PASS_THROUGH directives are filtered in DirectiveExtractionPass
        throw new LogicException(
            'Pass-through directives should not be compiled. ' .
            'They are registered only for type checking and are handled by other compiler passes.',
        );
    }
}
