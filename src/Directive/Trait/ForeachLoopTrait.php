<?php
declare(strict_types=1);

namespace Sugar\Directive\Trait;

use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;

/**
 * Trait for foreach-based loop directives (foreach, forelse)
 *
 * Provides shared functionality for:
 * - Wrapper pattern detection (element as container vs repeated element)
 * - Loop setup/teardown (LoopMetadata, loop stack management)
 * - Collection extraction from foreach expressions
 *
 * Wrapper Pattern:
 * ```
 * <ul s:foreach="$items as $item">
 *     <li><?= $item ?></li>
 * </ul>
 * ```
 * Result: `<ul>` becomes container, `<li>` elements repeat inside
 *
 * Repeat Pattern:
 * ```
 * <li s:foreach="$items as $item"><?= $item ?></li>
 * ```
 * Result: `<li>` element itself repeats
 */
trait ForeachLoopTrait
{
    /**
     * Detect if directive should use wrapper mode
     *
     * A directive uses wrapper mode when:
     * 1. It has exactly one child
     * 2. That child is an ElementNode
     * 3. That ElementNode has at least one ElementNode child (not leaf)
     *
     * @param \Sugar\Ast\DirectiveNode $node Directive node
     * @return bool True if should use wrapper mode
     */
    protected function shouldUseWrapperMode(Node $node): bool
    {
        // Must have exactly one child
        if (count($node->children) !== 1) {
            return false;
        }

        $child = $node->children[0];

        // Child must be an ElementNode
        if (!$child instanceof ElementNode) {
            return false;
        }

        // Count ElementNode children (not TextNodes or OutputNodes)
        $elementChildrenCount = count(array_filter(
            $child->children,
            fn(Node $c): bool => $c instanceof ElementNode,
        ));

        // If has ANY element children, treat as wrapper
        // Only repeat the element itself if it's a leaf (no element children)
        return $elementChildrenCount > 0;
    }

    /**
     * Get the wrapper element for wrapper mode
     *
     * Should only be called after shouldUseWrapperMode() returns true
     *
     * @param \Sugar\Ast\DirectiveNode $node Directive node
     * @return \Sugar\Ast\ElementNode Wrapper element
     */
    protected function getWrapperElement(Node $node): ElementNode
    {
        $child = $node->children[0];

        assert($child instanceof ElementNode, 'Wrapper element must be an ElementNode');

        return $child;
    }

    /**
     * Extract collection variable from foreach expression
     *
     * Examples:
     * - "$items as $item" -> "$items"
     * - "$users as $key => $user" -> "$users"
     * - "range(1, 10) as $i" -> "range(1, 10)"
     *
     * @param string $expression Foreach/forelse expression
     * @return string Collection expression
     */
    protected function extractCollection(string $expression): string
    {
        // Split on " as " and take the first part
        $parts = preg_split('/\s+as\s+/i', $expression, 2);

        return trim($parts[0] ?? $expression);
    }

    /**
     * Create loop setup nodes (stack push, LoopMetadata creation)
     *
     * @param string $collection Collection variable/expression
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Ast\RawPhpNode> Setup nodes
     */
    protected function createLoopSetup(string $collection, int $line, int $column): array
    {
        return [
            new RawPhpNode(
                '$__loopStack[] = $loop ?? null;',
                $line,
                $column,
            ),
            new RawPhpNode(
                sprintf(
                    '$loop = new \Sugar\Runtime\LoopMetadata(%s, end($__loopStack));',
                    $collection,
                ),
                $line,
                $column,
            ),
        ];
    }

    /**
     * Create foreach opening node
     *
     * @param string $expression Full foreach expression
     * @param int $line Line number
     * @param int $column Column number
     * @return \Sugar\Ast\RawPhpNode Foreach opening node
     */
    protected function createForeachOpening(string $expression, int $line, int $column): RawPhpNode
    {
        return new RawPhpNode(
            'foreach (' . $expression . '):',
            $line,
            $column,
        );
    }

    /**
     * Create loop teardown nodes (loop increment, endforeach, stack pop)
     *
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Ast\RawPhpNode> Teardown nodes
     */
    protected function createLoopTeardown(int $line, int $column): array
    {
        return [
            new RawPhpNode('$loop->next();', $line, $column),
            new RawPhpNode('endforeach;', $line, $column),
            new RawPhpNode('$loop = array_pop($__loopStack);', $line, $column),
        ];
    }

    /**
     * Compile loop in wrapper mode - element is container, children repeat inside
     *
     * @param \Sugar\Ast\DirectiveNode $node The loop directive node
     * @param \Sugar\Ast\ElementNode $wrapper The wrapper element
     * @param array<\Sugar\Ast\Node> $loopBody Loop body nodes (children to repeat)
     * @return \Sugar\Ast\ElementNode Wrapper element with loop inside
     */
    protected function compileLoopWrapper(Node $node, ElementNode $wrapper, array $loopBody): ElementNode
    {
        $collection = $this->extractCollection($node->expression);

        // Build loop content
        $loopContent = [
            ...$this->createLoopSetup($collection, $node->line, $node->column),
            $this->createForeachOpening($node->expression, $node->line, $node->column),
            ...$loopBody,
            ...$this->createLoopTeardown($node->line, $node->column),
        ];

        // Create wrapper element with loop content inside
        return new ElementNode(
            tag: $wrapper->tag,
            attributes: $wrapper->attributes,
            children: $loopContent,
            selfClosing: $wrapper->selfClosing,
            line: $wrapper->line,
            column: $wrapper->column,
        );
    }

    /**
     * Compile loop in repeat mode - directive element itself repeats
     *
     * @param \Sugar\Ast\DirectiveNode $node The loop directive node
     * @return array<\Sugar\Ast\Node> Loop nodes (setup, foreach, body, teardown)
     */
    protected function compileLoopRepeat(Node $node): array
    {
        $collection = $this->extractCollection($node->expression);

        return [
            ...$this->createLoopSetup($collection, $node->line, $node->column),
            $this->createForeachOpening($node->expression, $node->line, $node->column),
            ...$node->children,
            ...$this->createLoopTeardown($node->line, $node->column),
        ];
    }
}
