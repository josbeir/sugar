<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use Sugar\Exception\SugarException;

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
