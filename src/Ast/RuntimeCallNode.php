<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Represents a runtime call that renders to output
 */
final class RuntimeCallNode extends Node
{
    /**
     * @param string $callableExpression PHP expression to invoke (e.g., "Foo::bar" or "$obj->call")
     * @param array<string> $arguments PHP expressions for call arguments
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $callableExpression,
        public array $arguments,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
