<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\EmptyHelper;

/**
 * Compiler for empty directive
 *
 * Transforms s:empty directives into PHP empty() checks.
 *
 * Example:
 * ```
 * <div s:empty="$cart">Your cart is empty</div>
 * <div s:empty="$user->posts">No posts yet</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if (empty($cart)): ?>
 * <div>Your cart is empty</div>
 * <?php endif; ?>
 * ```
 */
readonly class EmptyCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [];

        // Opening control structure with empty check
        $parts[] = new RawPhpNode(
            'if (' . EmptyHelper::class . '::isEmpty(' . $node->expression . ')):',
            $node->line,
            $node->column,
        );

        // Children nodes (content to render when variable is empty)
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
