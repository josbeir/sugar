<?php
declare(strict_types=1);

namespace Sugar\Directive;

use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for switch/case/default directives
 *
 * Transforms s:switch with s:case and s:default children into PHP switch statements.
 *
 * Example:
 * ```
 * <div s:switch="$user->role">
 *     <div s:case="'admin'">Admin Panel</div>
 *     <div s:case="'moderator'">Moderator Tools</div>
 *     <div s:default>User Dashboard</div>
 * </div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php switch ($user->role): ?>
 *     <?php case 'admin': ?>
 *         <div>Admin Panel</div>
 *         <?php break; ?>
 *     <?php case 'moderator': ?>
 *         <div>Moderator Tools</div>
 *         <?php break; ?>
 *     <?php default: ?>
 *         <div>User Dashboard</div>
 * <?php endswitch; ?>
 * ```
 */
final readonly class SwitchCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Extract case and default children
        $cases = [];
        $default = null;
        $hasValidCases = false;

        foreach ($node->children as $child) {
            if (!$child instanceof DirectiveNode) {
                continue;
            }

            if ($child->name === 'case') {
                if (trim($child->expression) === '') {
                    throw new RuntimeException('Case directive requires a value expression');
                }
                $cases[] = $child;
                $hasValidCases = true;
            } elseif ($child->name === 'default') {
                if ($default !== null) {
                    throw new RuntimeException('Switch directive can only have one default case');
                }
                $default = $child;
                $hasValidCases = true;
            }
        }

        if (!$hasValidCases) {
            throw new RuntimeException('Switch directive must contain at least one case or default');
        }

        $parts = [];

        // Opening switch statement
        $parts[] = new RawPhpNode(
            'switch (' . $node->expression . '):',
            $node->line,
            $node->column,
        );

        // Compile each case
        foreach ($cases as $case) {
            $parts[] = new RawPhpNode(
                'case ' . $case->expression . ':',
                $case->line,
                $case->column,
            );

            // Case content
            array_push($parts, ...$case->children);

            // Auto-inject break after each case
            $parts[] = new RawPhpNode('break;', $case->line, $case->column);
        }

        // Compile default case if present
        if ($default !== null) {
            $parts[] = new RawPhpNode('default:', $default->line, $default->column);
            array_push($parts, ...$default->children);
            // No break needed after default (it's the last case)
        }

        // Closing switch statement
        $parts[] = new RawPhpNode('endswitch;', $node->line, $node->column);

        return $parts;
    }
}
