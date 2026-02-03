<?php
declare(strict_types=1);

namespace Sugar\Ast;

use Sugar\Ast\Interface\SiblingNavigationInterface;
use Sugar\Ast\Trait\SiblingNavigationTrait;

/**
 * Represents a component invocation in the template
 *
 * Example: <s-button type="primary" class="btn-large">Save</s-button>
 *
 * ComponentNode is replaced by ComponentExpansionPass with the component's
 * template content, with slots filled in.
 */
final class ComponentNode extends Node implements SiblingNavigationInterface
{
    use SiblingNavigationTrait;

    /**
     * @param string $name Component name (e.g., "button", "alert")
     * @param array<\Sugar\Ast\AttributeNode> $attributes Component attributes
     * @param array<\Sugar\Ast\Node> $children Default slot content
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $name,
        public array $attributes = [],
        public array $children = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
