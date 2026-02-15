<?php
declare(strict_types=1);

namespace Sugar\Core\Parser\Helper;

use Sugar\Core\Util\Hash;

/**
 * Shared low-level scanning primitives for HTML-like template source.
 */
final class HtmlScanHelper
{
    private const LINE_START_CACHE_MAX_ENTRIES = 512;

    /**
     * @var array<string, array<int, int>>
     */
    private array $lineStartCache = [];

    /**
     * Build an array of absolute offsets where each line starts.
     *
     * @return array<int, int>
     */
    public function buildLineStarts(string $source): array
    {
        $starts = [0];
        $position = -1;
        while (($position = strpos($source, "\n", $position + 1)) !== false) {
            $starts[] = $position + 1;
        }

        return $starts;
    }

    /**
     * Retrieve cached line-start offsets for a source fragment.
     *
     * @return array<int, int>
     */
    public function lineStartsForSource(string $source): array
    {
        $cacheKey = Hash::make($source);

        if (isset($this->lineStartCache[$cacheKey])) {
            $cached = $this->lineStartCache[$cacheKey];
            unset($this->lineStartCache[$cacheKey]);
            $this->lineStartCache[$cacheKey] = $cached;

            return $cached;
        }

        if (count($this->lineStartCache) >= self::LINE_START_CACHE_MAX_ENTRIES) {
            $oldestKey = array_key_first($this->lineStartCache);
            if (is_string($oldestKey)) {
                unset($this->lineStartCache[$oldestKey]);
            }
        }

        $this->lineStartCache[$cacheKey] = $this->buildLineStarts($source);

        return $this->lineStartCache[$cacheKey];
    }

    /**
     * Find the zero-based line index for an absolute offset.
     *
     * @param array<int, int> $lineStarts
     */
    public function findLineIndexFromStarts(array $lineStarts, int $offset): int
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
    public function findLineNumberFromStarts(array $lineStarts, int $offset): int
    {
        return $this->findLineIndexFromStarts($lineStarts, $offset) + 1;
    }

    /**
     * Read tag name end offset from a name start.
     */
    public function readTagNameEnd(string $source, int $start): int
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
    public function findTagEnd(string $source, int $start): ?int
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

    /**
     * Resolve a 1-based line and column for an offset within a fragment.
     *
     * @param array<int, int>|null $lineStarts Optional precomputed line starts for source
     * @return array{0: int, 1: int}
     */
    public function resolvePosition(
        string $source,
        int $offset,
        int $line,
        int $column,
        ?array $lineStarts = null,
    ): array {
        if ($offset <= 0) {
            return [$line, $column];
        }

        $length = strlen($source);
        if ($length === 0) {
            return [$line, $column];
        }

        if ($offset > $length) {
            $offset = $length;
        }

        $lineStarts ??= $this->lineStartsForSource($source);
        $lineIndex = $this->findLineIndexFromStarts($lineStarts, $offset);

        if ($lineIndex === 0) {
            return [$line, $column + $offset];
        }

        return [$line + $lineIndex, $offset - $lineStarts[$lineIndex] + 1];
    }
}
