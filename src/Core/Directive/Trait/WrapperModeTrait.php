<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Trait;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;

/**
 * Trait for directive wrapper mode detection
 *
 * Provides logic to determine if a directive should:
 * - **Wrapper mode**: Element acts as container, children repeat inside
 * - **Repeat mode**: Element itself repeats
 *
 * Used by loop directives (foreach, forelse, while) to determine
 * compilation strategy based on element structure.
 *
 * Wrapper mode example:
 * ```
 * <ul s:foreach="$items as $item">
 *     <li><?= $item ?></li>
 * </ul>
 * ```
 * Result: `<ul>` becomes container, `<li>` elements repeat inside
 *
 * Repeat mode example:
 * ```
 * <li s:foreach="$items as $item"><?= $item ?></li>
 * ```
 * Result: `<li>` element itself repeats
 */
trait WrapperModeTrait
{
    /**
     * Detect if directive should use wrapper mode
     *
     * A directive uses wrapper mode when:
     * 1. It has exactly one child
     * 2. That child is an ElementNode
     * 3. That ElementNode has exactly one meaningful ElementNode child
     * 4. Any TextNode children are whitespace-only
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node Directive node
     * @return bool True if should use wrapper mode
     */
    protected function shouldUseWrapperMode(Node $node): bool
    {
        // Must have exactly one child
        if (count($node->children) !== 1) {
            return false;
        }

        $child = $node->children[0];

        // Child must be an ElementNode
        if (!$child instanceof ElementNode) {
            return false;
        }

        $elementChildrenCount = 0;

        foreach ($child->children as $grandChild) {
            if ($grandChild instanceof ElementNode) {
                $elementChildrenCount++;

                continue;
            }

            if ($grandChild instanceof TextNode && trim($grandChild->content) === '') {
                continue;
            }

            return false;
        }

        return $elementChildrenCount === 1;
    }

    /**
     * Get the wrapper element for wrapper mode
     *
     * Should only be called after shouldUseWrapperMode() returns true
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node Directive node
     * @return \Sugar\Core\Ast\ElementNode Wrapper element
     */
    protected function getWrapperElement(Node $node): ElementNode
    {
        $child = $node->children[0];

        assert($child instanceof ElementNode, 'Wrapper element must be an ElementNode');

        return $child;
    }
}
