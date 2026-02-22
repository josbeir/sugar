<?php
declare(strict_types=1);

namespace Sugar\Core\Ast\Helper;

use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Exception\SyntaxException;

/**
 * Helper for validating PHP expressions at compile time
 */
final class ExpressionValidator
{
    /**
     * Normalize a directive/runtime expression.
     *
     * This helper supports three common normalization behaviors:
     * - empty input falls back to a provided expression (default `true`)
     * - known PHP expression prefixes/literals are preserved as-is
     * - optional bare-string pattern values are quoted via var_export
     *
     * @param string $expression Raw directive expression
     * @param string $emptyFallback Expression to use when input is empty
     * @param string|null $bareStringPattern Optional regex for bare values that should be treated as string literals
     * @return string Normalized PHP expression
     */
    public static function normalizeRuntimeExpression(
        string $expression,
        string $emptyFallback = 'true',
        ?string $bareStringPattern = null,
    ): string {
        $trimmed = trim($expression);
        if ($trimmed === '') {
            return $emptyFallback;
        }

        if (
            str_starts_with($trimmed, "'")
            || str_starts_with($trimmed, '"')
            || str_starts_with($trimmed, '[')
            || str_starts_with($trimmed, '$')
            || str_starts_with($trimmed, '(')
            || str_starts_with($trimmed, 'array(')
            || in_array(strtolower($trimmed), ['true', 'false', 'null'], true)
        ) {
            return $trimmed;
        }

        if ($bareStringPattern !== null && preg_match($bareStringPattern, $trimmed) === 1) {
            return var_export($trimmed, true);
        }

        return $trimmed;
    }

    /**
     * Validate that an expression could potentially be an array
     *
     * Detects obvious invalid cases (string/number/boolean literals) and throws SyntaxException.
     * Allows array literals, variables, and function calls (which might return arrays).
     *
     * @param string $expression PHP expression to validate
     * @param string $context Context for error message (e.g., 's:bind attribute', 's:spread value')
     * @param \Sugar\Core\Compiler\CompilationContext|null $compilationContext Compilation context for error location
     * @param int|null $line Line number for error reporting
     * @param int|null $column Column number for error reporting
     * @throws \Sugar\Core\Exception\SyntaxException If expression is obviously not an array
     */
    public static function validateArrayExpression(
        string $expression,
        string $context = 'expression',
        ?CompilationContext $compilationContext = null,
        ?int $line = null,
        ?int $column = null,
    ): void {
        $trimmed = trim($expression);

        // Allow array literals: ['key' => 'value'], array(...)
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, 'array(')) {
            return;
        }

        // Allow variables: $bindings, $this->bindings
        if (str_starts_with($trimmed, '$')) {
            return;
        }

        // Allow function/method calls: getBindings(), $obj->method(), static::method()
        if (str_contains($trimmed, '(')) {
            return;
        }

        // Allow ternary/null coalescing that might return array: $x ?? [], $x ? [] : []
        if (str_contains($trimmed, '?')) {
            return;
        }

        // Detect obvious non-array literals
        $message = null;

        // String literals: 'string', "string"
        if (preg_match('/^(["\']).*\1$/', $trimmed)) {
            $message = sprintf(
                '%s must be an array expression, string literal %s given. ' .
                "Use array syntax: ['key' => 'value']",
                ucfirst($context),
                $trimmed,
            );
        }

        // Number literals: 123, 45.67
        if ($message === null && is_numeric($trimmed)) {
            $message = sprintf(
                '%s must be an array expression, number literal %s given. ' .
                "Use array syntax: ['key' => 'value']",
                ucfirst($context),
                $trimmed,
            );
        }

        // Boolean/null literals: true, false, null
        if ($message === null && in_array(strtolower($trimmed), ['true', 'false', 'null'], true)) {
            $message = sprintf(
                '%s must be an array expression, %s given. ' .
                "Use array syntax: ['key' => 'value']",
                ucfirst($context),
                $trimmed,
            );
        }

        // Throw exception with context if available
        if ($message !== null) {
            if ($compilationContext instanceof CompilationContext && $line !== null && $column !== null) {
                throw $compilationContext->createSyntaxException($message, $line, $column);
            }

            throw new SyntaxException($message);
        }
    }

    /**
     * Check if an expression is potentially an array
     *
     * Returns true if the expression could be an array, false for obvious non-array literals.
     * This is a non-throwing version of validateArrayExpression.
     *
     * @param string $expression PHP expression to check
     * @return bool True if expression could be an array
     */
    public static function isPotentiallyArray(string $expression): bool
    {
        try {
            self::validateArrayExpression($expression);

            return true;
        } catch (SyntaxException) {
            return false;
        }
    }
}
