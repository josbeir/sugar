<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

/**
 * Utility for parsing pipe operator syntax
 *
 * Provides DRY parsing of PHP 8.5 pipe operators (|>) that can be used by:
 * - Parser (when parsing <?= ?> output tags)
 * - Directive compilers (when creating OutputNodes from directive expressions)
 *
 * Example:
 * ```php
 * $result = PipeParser::parse('$name |> strtoupper(...) |> substr(..., 0, 10)');
 * // Returns:
 * // [
 * //   'expression' => '$name',
 * //   'pipes' => ['strtoupper(...)', 'substr(..., 0, 10)']
 * // ]
 * ```
 */
final class PipeParser
{
    /**
     * Parse pipe syntax from expression
     *
     * Splits expression on |> operator and extracts pipe chain.
     * If no pipes are found, returns the original expression with null pipes.
     *
     * @param string $expression Expression to parse
     * @return array{expression: string, pipes: array<string>|null, raw: bool, json: bool} Base expression, pipe chain, and flags
     */
    public static function parse(string $expression): array
    {
        // Quick check - no pipe operator present
        if (!str_contains($expression, '|>')) {
            return ['expression' => $expression, 'pipes' => null, 'raw' => false, 'json' => false];
        }

        // Split by pipe operator (with optional whitespace)
        $parts = preg_split('/\s*\|\>\s*/', $expression);

        if ($parts === false || count($parts) < 2) {
            return ['expression' => $expression, 'pipes' => null, 'raw' => false, 'json' => false];
        }

        // First part is the base expression, rest are pipe transformations
        $baseExpression = trim(array_shift($parts));
        $pipes = array_map('trim', $parts);

        $raw = false;
        $json = false;
        $filteredPipes = [];
        foreach ($pipes as $pipe) {
            if (self::isPipeFunction($pipe, 'raw')) {
                $raw = true;
                continue;
            }

            if (self::isPipeFunction($pipe, 'json')) {
                $json = true;
                continue;
            }

            $filteredPipes[] = $pipe;
        }

        return [
            'expression' => $baseExpression,
            'pipes' => $filteredPipes !== [] ? $filteredPipes : null,
            'raw' => $raw,
            'json' => $json,
        ];
    }

    /**
     * Check if a pipe stage matches a no-arg function call.
     */
    private static function isPipeFunction(string $pipe, string $name): bool
    {
        $pattern = sprintf('/^%s\s*\(\s*\)$/', preg_quote($name, '/'));

        return preg_match($pattern, $pipe) === 1;
    }
}
