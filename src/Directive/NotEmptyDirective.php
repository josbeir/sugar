<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Runtime\EmptyHelper;

/**
 * Compiler for notempty directive
 *
 * Transforms s:notempty directives into negated EmptyHelper checks.
 *
 * Example:
 * ```
 * <div s:notempty="$cart">Cart has items</div>
 * <div s:notempty="$user->posts">Recent posts</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if (!\Sugar\Runtime\EmptyHelper::isEmpty($cart)): ?>
 * <div>Cart has items</div>
 * <?php endif; ?>
 * ```
 */
readonly class NotEmptyDirective extends EmptyDirective
{
    /**
     * Build the negated emptiness condition for s:notempty.
     *
     * @param string $expression Directive expression to evaluate.
     * @return string PHP condition expression.
     */
    protected function buildCondition(string $expression): string
    {
        return '!' . EmptyHelper::class . '::isEmpty(' . $expression . ')';
    }
}
