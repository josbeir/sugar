<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

use Sugar\Core\Ast\Interface\SiblingNavigationInterface;
use Sugar\Core\Ast\Trait\SiblingNavigationTrait;

/**
 * Root document node
 */
final class DocumentNode extends Node implements SiblingNavigationInterface
{
    use SiblingNavigationTrait;

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    public function __construct(
        public array $children,
        int $line = 1,
        int $column = 1,
    ) {
        parent::__construct($line, $column);
    }

    /**
     * Count child nodes
     */
    public function count(): int
    {
        return count($this->children);
    }
}
