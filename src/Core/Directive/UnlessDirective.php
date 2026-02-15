<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

/**
 * Compiler for unless directive (inverted if)
 *
 * Transforms s:unless directives into negated PHP if statements.
 *
 * Example:
 * ```
 * <div s:unless="$user->isAdmin()">Regular user content</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if (!($user->isAdmin())): ?>
 * <div>Regular user content</div>
 * <?php endif; ?>
 * ```
 */
readonly class UnlessDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [];

        // Opening control structure with negated condition
        $parts[] = new RawPhpNode(
            'if (!(' . $node->expression . ')):',
            $node->line,
            $node->column,
        );

        // Children nodes (content to render when condition is false)
        array_push($parts, ...$node->children);

        // Closing control structure
        $parts[] = new RawPhpNode('endif;', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
