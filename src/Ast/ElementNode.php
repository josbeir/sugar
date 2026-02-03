<?php
declare(strict_types=1);

namespace Sugar\Ast;

use Sugar\Ast\Interface\SiblingNavigationInterface;
use Sugar\Ast\Trait\SiblingNavigationTrait;

/**
 * HTML element node with attributes and children
 *
 * Represents a parsed HTML element that may contain:
 * - Static and dynamic attributes
 * - Child nodes (text, output, nested elements)
 * - Self-closing flag for void elements (img, br, input, etc.)
 */
final class ElementNode extends Node implements SiblingNavigationInterface
{
    use SiblingNavigationTrait;

    /**
     * @param string $tag HTML tag name (e.g., 'div', 'span', 'img')
     * @param array<\Sugar\Ast\AttributeNode> $attributes Element attributes
     * @param array<\Sugar\Ast\Node> $children Child nodes
     * @param bool $selfClosing Whether element is self-closing (<img /> vs <div></div>)
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $tag,
        public array $attributes,
        public array $children,
        public bool $selfClosing,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
