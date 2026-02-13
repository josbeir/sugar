<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

/**
 * Shared low-level scanning primitives for HTML-like template source.
 */
final class HtmlScanHelper
{
    /**
     * Build an array of absolute offsets where each line starts.
     *
     * @return array<int, int>
     */
    public static function buildLineStarts(string $source): array
    {
        $starts = [0];
        $position = -1;
        while (($position = strpos($source, "\n", $position + 1)) !== false) {
            $starts[] = $position + 1;
        }

        return $starts;
    }

    /**
     * Find the zero-based line index for an absolute offset.
     *
     * @param array<int, int> $lineStarts
     */
    public static function findLineIndexFromStarts(array $lineStarts, int $offset): int
    {
        if ($offset <= 0) {
            return 0;
        }

        $low = 0;
        $high = count($lineStarts) - 1;
        $lineIndex = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($lineStarts[$mid] <= $offset) {
                $lineIndex = $mid;
                $low = $mid + 1;
                continue;
            }

            $high = $mid - 1;
        }

        return $lineIndex;
    }

    /**
     * Resolve 1-based line number for absolute source offset using line starts.
     *
     * @param array<int, int> $lineStarts
     */
    public static function findLineNumberFromStarts(array $lineStarts, int $offset): int
    {
        return self::findLineIndexFromStarts($lineStarts, $offset) + 1;
    }

    /**
     * Read tag name end offset from a name start.
     */
    public static function readTagNameEnd(string $source, int $start): int
    {
        $length = strlen($source);
        $offset = $start;
        while ($offset < $length && (ctype_alnum($source[$offset]) || $source[$offset] === '-')) {
            $offset++;
        }

        return $offset;
    }

    /**
     * Find the offset immediately after a tag's closing `>` while honoring quoted attributes.
     */
    public static function findTagEnd(string $source, int $start): ?int
    {
        $length = strlen($source);
        $quote = null;

        for ($offset = $start; $offset < $length; $offset++) {
            $char = $source[$offset];

            if ($quote !== null) {
                if ($char === $quote) {
                    $backslashCount = 0;
                    for ($scan = $offset - 1; $scan >= 0 && $source[$scan] === '\\'; $scan--) {
                        $backslashCount++;
                    }

                    if ($backslashCount % 2 === 1) {
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $offset + 1;
            }
        }

        return null;
    }
}
