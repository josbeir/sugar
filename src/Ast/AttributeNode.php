<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * HTML attribute node (static or dynamic value)
 *
 * Represents an HTML attribute with either:
 * - Static string value: <div class="container">
 * - Dynamic OutputNode value: <div class="<?= $class ?>">
 * - Mixed parts (strings + OutputNodes) for complex attributes
 * - Null value for boolean attributes: <input disabled>
 */
final class AttributeNode extends Node
{
    /**
     * @param string $name Attribute name (e.g., 'class', 'id', 'href')
     * @param \Sugar\Ast\OutputNode|array<int, string|\Sugar\Ast\OutputNode>|string|null $value Attribute value
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $name,
        public string|OutputNode|array|null $value,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
