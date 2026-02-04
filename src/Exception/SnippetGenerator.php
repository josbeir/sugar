<?php
declare(strict_types=1);

namespace Sugar\Exception;

/**
 * Generate code snippets showing template context around errors
 */
final class SnippetGenerator
{
    /**
     * Generate a snippet showing context around an error location
     *
     * @param string $source Template source code
     * @param int $line Error line number (1-based)
     * @param int $column Error column number (1-based)
     * @param int $contextLines Number of lines to show before and after error
     * @return string Formatted snippet with line numbers and error pointer
     */
    public static function generate(
        string $source,
        int $line,
        int $column,
        int $contextLines = 2,
    ): string {
        $lines = explode("\n", $source);
        $totalLines = count($lines);

        // Calculate line range
        $startLine = max(1, $line - $contextLines);
        $endLine = min($totalLines, $line + $contextLines);

        // Calculate padding for line numbers (minimum 2 for consistent double-space)
        $maxLineNumber = $endLine;
        $padding = max(2, strlen((string)$maxLineNumber));

        $snippetLines = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineNumber = str_pad((string)$i, $padding, ' ', STR_PAD_LEFT);
            $lineContent = $lines[$i - 1]; // Arrays are 0-indexed
            $snippetLines[] = sprintf('%s | %s', $lineNumber, $lineContent);

            // Add error pointer after the error line
            if ($i === $line) {
                $pointerPadding = $padding + 3 + $column - 1; // padding + " | " + column position
                $pointer = str_repeat(' ', $pointerPadding) . '^';
                $snippetLines[] = $pointer;
            }
        }

        return implode("\n", $snippetLines);
    }
}
