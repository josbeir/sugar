<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

/**
 * Formats full template output with a caret row at the error column.
 */
final class TemplateHighlightFormatter
{
    /**
     * Format a full template with a caret row for the error column.
     */
    public function format(string $source, int $line, int $column): TemplateHighlightResult
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $lines = $source === '' ? [] : explode("\n", $source);
        $totalLines = count($lines);

        if ($totalLines === 0) {
            return new TemplateHighlightResult([]);
        }

        $padding = strlen((string)$totalLines);
        $formatted = [];

        foreach ($lines as $index => $content) {
            $lineNumber = $index + 1;
            $prefix = str_pad((string)$lineNumber, $padding, '0', STR_PAD_LEFT) . ' | ';
            $formatted[] = new TemplateHighlightLine(
                $prefix . $content,
                $lineNumber === $line,
                false,
            );

            if ($lineNumber === $line && $column > 0) {
                $caretPrefix = str_repeat('0', $padding) . ' | ';
                $caret = $caretPrefix . str_repeat(' ', max(0, $column - 1)) . '^';
                $formatted[] = new TemplateHighlightLine($caret, false, true);
            }
        }

        return new TemplateHighlightResult($formatted);
    }
}
