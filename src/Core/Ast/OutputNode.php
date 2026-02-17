<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

use Sugar\Core\Escape\Enum\OutputContext;

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
     * @param \Sugar\Core\Escape\Enum\OutputContext $context Pre-determined at compile-time
     * @param int $line Line number
     * @param int $column Column number
     * @param array<string>|null $pipes Pipe transformations (e.g., ['upper(...)', 'truncate(..., 20)'])
     */
    public function __construct(
        public string $expression,
        public bool $escape,
        public OutputContext $context,
        int $line,
        int $column,
        public ?array $pipes = null,
    ) {
        parent::__construct($line, $column);
    }
}
