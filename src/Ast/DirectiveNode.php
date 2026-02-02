<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Structural directive node (if, foreach, while, etc.)
 *
 * Represents control flow directives using s:* attributes:
 * - s:if / s:elseif / s:else - Conditional rendering
 * - s:foreach / s:each - Loop iteration
 * - s:while - While loops
 */
final class DirectiveNode extends Node
{
    /**
     * @param string $name Directive name (e.g., 'if', 'foreach', 'while')
     * @param string $expression PHP expression for the directive
     * @param array<\Sugar\Ast\Node> $children Child nodes to render when condition is true
     * @param array<\Sugar\Ast\Node>|null $elseChildren Child nodes for else branch (null if no else)
     * @param int $line Line number in source template
     * @param int $column Column number in source template
     */
    public function __construct(
        public string $name,
        public string $expression,
        public array $children,
        public ?array $elseChildren,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
