<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

/**
 * Compiler for if/elseif/else directives
 *
 * Transforms s:if, s:elseif, and s:else directives into PHP control structures.
 * Uses sibling pairing to handle elseif/else chains.
 *
 * Example:
 * ```
 * <div s:if="$user">Welcome</div>
 * <div s:elseif="$guest">Hello</div>
 * <div s:else>Hi</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if ($user): ?>
 * <div>Welcome</div>
 * <?php elseif ($guest): ?>
 * <div>Hello</div>
 * <?php else: ?>
 * <div>Hi</div>
 * <?php endif; ?>
 * ```
 */
readonly class IfDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
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
        array_push($parts, ...$node->children);

        // Follow pairedSibling chain for elseif/else
        $paired = $node->getPairedSibling();
        if ($paired !== null) {
            // Recursively compile the paired directive (elseif/else)
            array_push($parts, ...$this->compile($paired, $context));
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
