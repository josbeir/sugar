<?php
declare(strict_types=1);

namespace Sugar;

/**
 * Template engine interface
 *
 * High-level API for template compilation and rendering.
 */
interface EngineInterface
{
    /**
     * Render a template with data
     *
     * @param string $template Template path or inline template
     * @param array<string, mixed> $data Template variables
     * @return string Rendered output
     */
    public function render(string $template, array $data = []): string;

    /**
     * Compile a template to PHP code
     *
     * @param string $template Template path or inline template
     * @return string Compiled PHP code
     */
    public function compile(string $template): string;
}
