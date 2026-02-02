<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Extension\DirectiveCompilerInterface;

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
final readonly class IssetCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
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
}
