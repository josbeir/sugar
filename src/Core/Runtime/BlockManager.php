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
     * Depth counter tracking how many `renderExtends()` calls are currently active.
     *
     * Zero means we are in "defining context" — a child template is setting up
     * its block overrides before calling `renderExtends()`. Greater than zero means
     * a parent layout is actively rendering via `renderExtends()`, and any `s:block`
     * encountered should act as a layout placeholder rather than a definition.
     */
    private int $renderingDepth = 0;

    /**
     * Depth counter for block registration mode.
     *
     * While this is greater than zero, `renderBlock()` behaves as `defineBlock()`:
     * the default closure (partial content) is registered as a child override rather
     * than being rendered. This mode is entered before pre-extends include calls so
     * that `s:block` in included partials can register block overrides for the
     * parent layout.
     */
    private int $blockRegistrationDepth = 0;

    /**
     * Push a new block definition level onto the stack.
     *
     * Called when entering an extends render. New levels are appended at the end,
     * so child blocks remain at lower indices and are found first during search.
     */
    public function pushLevel(): void
    {
        $this->levels[] = [];
        $this->renderingDepth++;
    }

    /**
     * Pop the top block definition level from the stack.
     *
     * Called when leaving an extends render.
     */
    public function popLevel(): void
    {
        array_pop($this->levels);
        $this->renderingDepth--;
    }

    /**
     * Determine whether we are in a block-definition context.
     *
     * Returns `true` when no `renderExtends()` call is currently active, meaning
     * a child template is still setting up its block overrides. In this context,
     * `s:block` in an included partial should call `defineBlock()` rather than
     * `renderBlock()`, so that the partial's content is registered as a child
     * override for the parent layout.
     *
     * Returns `false` once a parent layout is being rendered via `renderExtends()`;
     * in that phase, `s:block` acts as a layout placeholder using `renderBlock()`.
     */
    public function isDefiningContext(): bool
    {
        return $this->renderingDepth === 0;
    }

    /**
     * Enter block registration mode.
     *
     * While in this mode, `renderBlock()` acts as `defineBlock()`: the default
     * closure (partial content) is stored as a child block override rather than
     * being rendered. Nested calls are supported via a depth counter.
     *
     * Called before each pre-extends `renderInclude()` so that `s:block`
     * directives in included partials register their content as overrides.
     */
    public function enterBlockRegistration(): void
    {
        $this->blockRegistrationDepth++;
    }

    /**
     * Exit block registration mode.
     *
     * Decrements the depth counter set by `enterBlockRegistration()`. When the
     * counter reaches zero the manager returns to normal rendering behavior.
     * Safe to call from a finally block: has no effect if the counter is already
     * at zero (guards against double-decrement on exception paths).
     */
    public function exitBlockRegistration(): void
    {
        if ($this->blockRegistrationDepth > 0) {
            $this->blockRegistrationDepth--;
        }
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
     * Check whether a block override has been defined in the current inheritance levels.
     *
     * Returns true only when any level explicitly defines the named block.
     * This does not consider parent layout defaults passed to renderBlock().
     */
    public function hasDefinedBlock(string $name): bool
    {
        foreach ($this->levels as $level) {
            if (isset($level[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a block by name, falling back to the default closure.
     *
     * When in block registration mode (inside a pre-extends `renderInclude()` call),
     * the default closure is registered via `defineBlock()` instead of rendered.
     * This allows `s:block` directives in included partials to propagate their
     * content as child block overrides for the parent layout.
     *
     * In normal rendering mode, searches through the level stack from most-derived
     * (child) to least-derived (grandparent) and renders the first match. If no
     * override is found, the default closure (inline parent content) is rendered.
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
        // In block registration mode, store the default closure as a child override.
        // The output of any element wrappers (like <div>) is intentionally discarded by
        // the pre-extends include mechanism that wraps the renderInclude() call.
        if ($this->blockRegistrationDepth > 0) {
            $this->defineBlock($name, $default);

            return '';
        }

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
     * Clears all levels, the render stack, and depth counters for a fresh
     * template render. Both `renderingDepth` and `blockRegistrationDepth` are
     * restored to zero so that `isDefiningContext()` and block-registration mode
     * behave correctly even after a previous render that threw an exception.
     */
    public function reset(): void
    {
        $this->levels = [];
        $this->renderStack = [];
        $this->renderingDepth = 0;
        $this->blockRegistrationDepth = 0;
    }
}
