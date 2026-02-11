<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use Sugar\Exception\SugarException;

/**
 * Provides template source for exception rendering.
 */
interface SourceProviderInterface
{
    /**
     * Return template source for the provided exception.
     */
    public function getSource(SugarException $exception): ?string;
}
