<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

/**
 * Value object for highlighted template output.
 */
final readonly class TemplateHighlightResult
{
    /**
     * @param array<\Sugar\Exception\Renderer\TemplateHighlightLine> $lines
     */
    public function __construct(public array $lines)
    {
    }
}
