<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

/**
 * Value object for component slot content.
 *
 * Holds named slots keyed by slot name and the default slot content.
 */
final readonly class ComponentSlots
{
    /**
     * @param array<string, array<\Sugar\Ast\Node>> $namedSlots
     * @param array<\Sugar\Ast\Node> $defaultSlot
     */
    public function __construct(
        public array $namedSlots,
        public array $defaultSlot,
    ) {
    }
}
