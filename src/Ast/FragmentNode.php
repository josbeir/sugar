<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Fragment node - renders children without wrapper element
 *
 * Represents <s-template> elements that accept directives
 * but don't render the element itself, only its children.
 *
 * Can be self-closing when used for directive-only markup.
 *
 * This is useful when you need to apply directives without
 * adding an extra wrapper element to the DOM.
 *
 * Example:
 * ```
 * <s-template s:foreach="$items as $item">
 *     <div><?= $item ?></div>
 * </s-template>
 * ```
 *
 * Compiles to just the div elements without a wrapper.
 */
final class FragmentNode extends Node
{
    /**
     * @param array<\Sugar\Ast\AttributeNode> $attributes Sugar directives (s:* only)
     * @param array<\Sugar\Ast\Node> $children Child nodes to render
     * @param int $line Line number in source
     * @param int $column Column number in source
     * @param bool $selfClosing Whether the fragment is self-closing
     */
    public function __construct(
        public readonly array $attributes,
        public array $children, // NOT readonly - parser mutates this during tree building
        public readonly int $line,
        public readonly int $column,
        public bool $selfClosing = false,
    ) {
    }
}
