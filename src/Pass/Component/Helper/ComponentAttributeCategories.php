<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

use Sugar\Ast\AttributeNode;

/**
 * Value object for component attribute buckets.
 *
 * Separates attributes into control-flow directives, attribute directives,
 * component bindings (`s:bind`), and regular HTML attributes to merge.
 */
final readonly class ComponentAttributeCategories
{
    /**
     * @param array<\Sugar\Ast\AttributeNode> $controlFlow
     * @param array<\Sugar\Ast\AttributeNode> $attributeDirectives
     * @param array<\Sugar\Ast\AttributeNode> $merge
     */
    public function __construct(
        public array $controlFlow,
        public array $attributeDirectives,
        public ?AttributeNode $componentBindings,
        public array $merge,
    ) {
    }
}
