<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

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
final readonly class UnlessCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
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
