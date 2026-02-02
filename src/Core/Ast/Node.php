<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

/**
 * Base node for all AST elements
 */
abstract class Node
{
    /**
     * Constructor
     *
     * @param int $line Line number
     * @param int $column Column number
     */
    public function __construct(
        public readonly int $line,
        public readonly int $column,
    ) {
    }
}
