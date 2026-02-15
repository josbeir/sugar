<?php
declare(strict_types=1);

namespace Sugar\Core\Runtime;

use Countable;
use RuntimeException;

/**
 * Loop metadata for template foreach iterations
 *
 * Provides information about the current loop iteration, similar to
 * Blade's $loop variable or Twig's loop object.
 *
 * Properties:
 * - index: 0-based current index (0, 1, 2...)
 * - iteration: 1-based current iteration (1, 2, 3...)
 * - first: true on first iteration
 * - odd: true if iteration is odd
 * - even: true if iteration is even
 * - depth: nesting level (1 for top level, 2 for nested, etc.)
 * - parent: parent loop metadata (null if top level)
 *
 * Count-dependent properties (null for non-countable iterables like generators):
 * - count: total number of items (null if unknown)
 * - last: true on last iteration (null if count unknown)
 * - remaining: items remaining after current (null if count unknown)
 *
 * Note: Generators and non-countable iterators are NOT materialized into arrays,
 * preserving memory efficiency. Use arrays or Countable objects if you need
 * count/last/remaining properties.
 *
 * @property-read int $index
 * @property-read int $iteration
 * @property-read bool $first
 * @property-read bool $odd
 * @property-read bool $even
 * @property-read int $depth
 * @property-read self|null $parent
 * @property-read int|null $count
 * @property-read bool|null $last
 * @property-read int|null $remaining
 */
final class LoopMetadata
{
    private int $index = 0;

    private readonly ?int $count;

    /**
     * @param iterable<mixed> $items Items being iterated (arrays, generators, iterators, etc.)
     * @param \Sugar\Core\Runtime\LoopMetadata|null $parent Parent loop (for nested loops)
     */
    public function __construct(
        iterable $items,
        private readonly ?self $parent = null,
    ) {
        // Only determine count if items are efficiently countable
        // This avoids materializing generators/iterators into memory
        if (is_array($items)) {
            $this->count = count($items);
        } elseif ($items instanceof Countable) {
            $this->count = count($items);
        } else {
            // Generator or non-countable iterator - count unknown
            // Properties like last/remaining will return null
            $this->count = null;
        }
    }

    /**
     * Increment to next iteration
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * Magic getter for loop properties
     *
     * @param string $name Property name
     * @return mixed Property value
     * @throws \RuntimeException If property doesn't exist
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'index' => $this->index,
            'iteration' => $this->index + 1,
            'first' => $this->index === 0,
            'odd' => ($this->index + 1) % 2 === 1,
            'even' => ($this->index + 1) % 2 === 0,
            'depth' => $this->parent instanceof LoopMetadata ? $this->parent->depth + 1 : 1,
            'parent' => $this->parent,
            // Count-dependent properties: return null if count unknown
            'count' => $this->count,
            'last' => $this->count !== null ? $this->index === $this->count - 1 : null,
            'remaining' => $this->count !== null ? $this->count - $this->index - 1 : null,
            default => throw new RuntimeException('Unknown loop property: ' . $name),
        };
    }
}
