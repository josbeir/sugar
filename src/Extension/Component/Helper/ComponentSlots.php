<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Helper;

/**
 * Value object for component slot content and metadata.
 *
 * Holds named slots keyed by slot name, the default slot content,
 * and metadata about caller slot elements (tag and attributes) for
 * tag swapping and attribute merging in slot outlets.
 */
final readonly class ComponentSlots
{
    /**
     * @param array<string, array<\Sugar\Core\Ast\Node>> $namedSlots Named slot content nodes (inner content only)
     * @param array<\Sugar\Core\Ast\Node> $defaultSlot Default slot content nodes
     * @param array<string, array{tag: string, attrs: array<\Sugar\Core\Ast\AttributeNode>}> $namedSlotMeta Metadata about caller slot elements (tag and attributes)
     */
    public function __construct(
        public array $namedSlots,
        public array $defaultSlot,
        public array $namedSlotMeta = [],
    ) {
    }
}
