<?php
declare(strict_types=1);

namespace Sugar\Exception;

/**
 * Exception thrown when attempting to use a Generator with empty checks
 *
 * Generators cannot be checked for emptiness without consuming them,
 * which would prevent their use in subsequent foreach loops.
 *
 * Solution: Convert generator to array before use in templates:
 * ```php
 * $items = iterator_to_array($generator);
 * $engine->render($template, ['items' => $items]);
 * ```
 */
final class GeneratorNotSupportedException extends TemplateRuntimeException
{
    protected string $defaultMessage = 'Generators cannot be used with s:empty or s:forelse directives. ' .
        'Convert to array first using iterator_to_array($generator) before passing to template.';
}
