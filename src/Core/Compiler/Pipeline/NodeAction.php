<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

use Sugar\Core\Ast\Node;

/**
 * Result of a compiler hook invocation.
 */
final class NodeAction
{
    /**
     * @param array<\Sugar\Core\Ast\Node>|null $replaceWith
     */
    public function __construct(
        public readonly ?array $replaceWith = null,
        public readonly bool $skipChildren = false,
        public readonly bool $restartPass = false,
    ) {
    }

    /**
     * No-op action.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Skip traversal of the current node's children.
     */
    public static function skipChildren(): self
    {
        return new self(null, true, false);
    }

    /**
     * @param \Sugar\Core\Ast\Node|array<\Sugar\Core\Ast\Node> $replacement
     * @param bool $restartPass Whether replacement should be processed by the same pass
     */
    public static function replace(Node|array $replacement, bool $restartPass = false): self
    {
        $nodes = is_array($replacement) ? $replacement : [$replacement];

        return new self($nodes, false, $restartPass);
    }
}
