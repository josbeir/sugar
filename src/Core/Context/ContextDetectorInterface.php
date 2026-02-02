<?php
declare(strict_types=1);

namespace Sugar\Core\Context;

use Sugar\Core\Enum\OutputContext;

/**
 * Interface for detecting output context
 */
interface ContextDetectorInterface
{
    /**
     * Detect context from position in template source
     */
    public function detect(int $position, string $source): OutputContext;
}
