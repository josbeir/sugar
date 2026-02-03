<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for if/elseif/else directives
 *
 * Transforms s:if, s:elseif, and s:else directives into PHP control structures.
 *
 * Example:
 * ```
 * <div s:if="$user">Welcome</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if ($user): ?>
 * <div>Welcome</div>
 * <?php endif; ?>
 * ```
 */
final readonly class IfCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        $parts = [];

        // Opening control structure
        if ($node->name === 'if') {
            $parts[] = new RawPhpNode('if (' . $node->expression . '):', $node->line, $node->column);
        } elseif ($node->name === 'elseif') {
            $parts[] = new RawPhpNode('elseif (' . $node->expression . '):', $node->line, $node->column);
        } else { // 'else'
            $parts[] = new RawPhpNode('else:', $node->line, $node->column);
        }

        // Children nodes (content to render when condition is true)
        // Note: Children are added as-is. The DirectiveCompilationPass will
        // recursively compile any nested DirectiveNodes in the children.
        array_push($parts, ...$node->children);

        // Else branch if present
        if ($node->elseChildren !== null) {
            $parts[] = new RawPhpNode('else:', $node->line, $node->column);
            array_push($parts, ...$node->elseChildren);
        }

        // Closing control structure (only for 'if')
        if ($node->name === 'if') {
            $parts[] = new RawPhpNode('endif;', $node->line, $node->column);
        }

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
