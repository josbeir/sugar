<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
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
readonly class EmptyDirective implements DirectiveInterface
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
            'if (' . $this->buildCondition($node->expression) . '):',
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
     * Build the condition used by the generated `if` statement.
     *
     * @param string $expression Directive expression to evaluate.
     * @return string PHP condition expression.
     */
    protected function buildCondition(string $expression): string
    {
        return EmptyHelper::class . '::isEmpty(' . $expression . ')';
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
