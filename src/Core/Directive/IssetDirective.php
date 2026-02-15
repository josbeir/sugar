<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

/**
 * Compiler for isset directive
 *
 * Transforms s:isset directives into PHP isset() checks.
 * Supports comma-separated variables for multiple checks.
 *
 * Example:
 * ```
 * <div s:isset="$user">Welcome, <?= $user->name ?></div>
 * <div s:isset="$user, $profile">Both exist</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if (isset($user)): ?>
 * <div>Welcome, <?= htmlspecialchars($user->name) ?></div>
 * <?php endif; ?>
 * ```
 */
readonly class IssetDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [];

        // Opening control structure with isset check
        $parts[] = new RawPhpNode(
            'if (isset(' . $node->expression . ')):',
            $node->line,
            $node->column,
        );

        // Children nodes (content to render when variable is set)
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
