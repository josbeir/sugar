<?php
declare(strict_types=1);

namespace Sugar\Core\Runtime;

use Closure;
use Sugar\Core\Exception\TemplateRuntimeException;

/**
 * Manages block definitions and rendering for template inheritance at runtime.
 *
 * Maintains a stack of block definition maps (one per extends level). Child
 * templates push their block definitions before rendering the parent layout,
 * allowing the most-derived block to win when the layout calls `renderBlock()`.
 *
 * Example flow for `child extends parent`:
 * 1. Child compiled template defines blocks via `defineBlock()`
 * 2. Child calls `renderExtends()` which pushes a new level
 * 3. Parent layout calls `renderBlock('name', $default)` for each placeholder
 * 4. BlockManager returns the child override or falls back to the default
 */
final class BlockManager
{
    /**
     * Stack of block definition levels (child-first order).
     *
     * Each level is a map of block name to its rendering closure.
     *
     * @var array<int, array<string, \Closure(array<string, mixed>): string>>
     */
    private array $levels = [];

    /**
     * Stack tracking which block is currently being rendered.
     *
     * Used by `renderParent()` to determine the next level up and the parent default.
     *
     * @var array<int, array{name: string, level: int, default: \Closure(array<string, mixed>): string}>
     */
    private array $renderStack = [];

    /**
     * Push a new block definition level onto the stack.
     *
     * Called when entering an extends render. New levels are appended at the end,
     * so child blocks remain at lower indices and are found first during search.
     */
    public function pushLevel(): void
    {
        $this->levels[] = [];
    }

    /**
     * Pop the top block definition level from the stack.
     *
     * Called when leaving an extends render.
     */
    public function popLevel(): void
    {
        array_pop($this->levels);
    }

    /**
     * Define a block at the current (last) level.
     *
     * @param string $name Block name
     * @param \Closure(array<string, mixed>): string $block Closure that renders the block content
     */
    public function defineBlock(string $name, Closure $block): void
    {
        if ($this->levels === []) {
            $this->levels[] = [];
        }

        $lastIndex = array_key_last($this->levels);
        $this->levels[$lastIndex][$name] = $block;
    }

    /**
     * Render a block by name, falling back to the default closure.
     *
     * Searches through the level stack from most-derived (child) to least-derived
     * (grandparent) and renders the first match. If no override is found, the
     * default closure (inline parent content) is rendered.
     *
     * The default closure is stored in the render stack so that `renderParent()`
     * can access it when the child block uses `s:parent`.
     *
     * @param string $name Block name
     * @param \Closure(array<string, mixed>): string $default Default content closure
     * @param array<string, mixed> $data Template variables
     * @return string Rendered block content
     */
    public function renderBlock(string $name, Closure $default, array $data): string
    {
        // Search from most-derived (index 0) to least-derived
        foreach ($this->levels as $levelIndex => $level) {
            if (isset($level[$name])) {
                $this->renderStack[] = ['name' => $name, 'level' => $levelIndex, 'default' => $default];

                try {
                    return $level[$name]($data);
                } finally {
                    array_pop($this->renderStack);
                }
            }
        }

        // No override found — use default (parent) content
        return $default($data);
    }

    /**
     * Render the parent version of the currently active block.
     *
     * Searches the level stack starting from the next level after the one currently
     * rendering this block. This allows `s:parent` to render the parent's content
     * while the child adds its own content around it.
     *
     * Falls back to the parent's default content (stored in the render stack by
     * `renderBlock()`) when no further overrides are found.
     *
     * @param string $name Block name (must match the currently active block)
     * @param \Closure(array<string, mixed>): string $parentDefault Unused compile-time placeholder
     * @param array<string, mixed> $data Template variables
     * @return string Rendered parent block content
     * @throws \Sugar\Core\Exception\TemplateRuntimeException When called outside a block render
     */
    public function renderParent(string $name, Closure $parentDefault, array $data): string
    {
        if ($this->renderStack === []) {
            throw new TemplateRuntimeException(
                sprintf('Cannot call renderParent() for block "%s" outside of block rendering.', $name),
            );
        }

        $current = end($this->renderStack);
        $startLevel = $current['level'] + 1;
        $levelCount = count($this->levels);

        // Search from the next level down
        for ($i = $startLevel; $i < $levelCount; $i++) {
            if (isset($this->levels[$i][$name])) {
                $this->renderStack[] = ['name' => $name, 'level' => $i, 'default' => $current['default']];

                try {
                    return $this->levels[$i][$name]($data);
                } finally {
                    array_pop($this->renderStack);
                }
            }
        }

        // No further override — render the parent's actual default content
        return $current['default']($data);
    }

    /**
     * Check whether any block levels are currently active.
     *
     * @return bool True when at least one level has been pushed
     */
    public function hasLevels(): bool
    {
        return $this->levels !== [];
    }

    /**
     * Reset the block manager state.
     *
     * Clears all levels and the render stack for a fresh template render.
     */
    public function reset(): void
    {
        $this->levels = [];
        $this->renderStack = [];
    }
}
