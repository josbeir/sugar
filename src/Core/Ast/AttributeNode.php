<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

/**
 * HTML attribute node (static or dynamic value)
 *
 * Represents an HTML attribute with either:
 * - Static string value: <div class="container">
 * - Dynamic OutputNode value: <div class="<?= $class ?>">
 * - Mixed parts (strings + OutputNodes) for complex attributes
 * - Boolean value for attributes like <input disabled>
 */
final class AttributeNode extends Node
{
    /**
     * @param string $name Attribute name (e.g., 'class', 'id', 'href')
     * @param \Sugar\Core\Ast\AttributeValue $value Attribute value
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $name,
        public AttributeValue $value,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
