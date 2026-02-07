<?php
declare(strict_types=1);

namespace Sugar\Escape;

use Sugar\Enum\OutputContext;

/**
 * Interface for context-aware escaping
 */
interface EscaperInterface
{
    /**
     * Escape value based on output context
     */
    public function escape(mixed $value, OutputContext $context): string;

    /**
     * Generate inline PHP code for escaping (compile-time optimization)
     * Returns a PHP expression that can be embedded directly in compiled templates
     *
     * @param string $expression PHP expression to escape
     * @param \Sugar\Enum\OutputContext $context Output context
     * @return string PHP code expression
     */
    public function generateEscapeCode(string $expression, OutputContext $context): string;
}
