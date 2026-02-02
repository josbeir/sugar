<?php
declare(strict_types=1);

namespace Sugar\Ast;

use Sugar\Enum\OutputContext;

/**
 * Dynamic output with context-aware escaping
 */
final class OutputNode extends Node
{
    /**
     * Constructor
     *
     * @param string $expression PHP expression to evaluate
     * @param bool $escape True = s:text, false = s:html
     * @param \Sugar\Enum\OutputContext $context Pre-determined at compile-time
     * @param int $line Line number
     * @param int $column Column number
     */
    public function __construct(
        public string $expression,
        public bool $escape,
        public OutputContext $context,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
