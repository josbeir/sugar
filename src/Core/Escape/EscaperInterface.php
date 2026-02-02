<?php
declare(strict_types=1);

namespace Sugar\Core\Escape;

use Sugar\Core\Enum\OutputContext;

/**
 * Interface for context-aware escaping
 */
interface EscaperInterface
{
    /**
     * Escape value based on output context
     */
    public function escape(mixed $value, OutputContext $context): string;
}
