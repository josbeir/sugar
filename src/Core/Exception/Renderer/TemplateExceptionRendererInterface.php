<?php
declare(strict_types=1);

namespace Sugar\Core\Exception\Renderer;

use Sugar\Core\Exception\SugarException;

/**
 * Renders template exceptions into a formatted string.
 */
interface TemplateExceptionRendererInterface
{
    /**
     * Render an exception into a formatted string.
     */
    public function render(SugarException $exception): string;
}
