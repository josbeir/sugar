<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\Trait\ForeachLoopTrait;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for while directive
 *
 * Transforms s:while directives into PHP while loops.
 *
 * Supports two modes:
 * 1. **Wrapper mode**: Element has child elements - acts as container
 * 2. **Repeat mode**: Element is leaf - repeats itself
 *
 * Wrapper mode example:
 * ```
 * <div s:while="$counter < 3">
 *     <span>Count: <?= $counter++ ?></span>
 * </div>
 * ```
 * Compiles to:
 * ```php
 * <div>
 * <?php while ($counter < 3): ?>
 *     <span>Count: <?= $counter++ ?></span>
 * <?php endwhile; ?>
 * </div>
 * ```
 *
 * Repeat mode example:
 * ```
 * <div s:while="$counter < 3">Count: <?= $counter++ ?></div>
 * ```
 * Compiles to:
 * ```php
 * <?php while ($counter < 3): ?>
 * <div>Count: <?= $counter++ ?></div>
 * <?php endwhile; ?>
 * ```
 */
final readonly class WhileCompiler implements DirectiveCompilerInterface
{
    use ForeachLoopTrait;

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Check if we should use wrapper mode (element as container)
        if ($this->shouldUseWrapperMode($node)) {
            return $this->compileWithWrapper($node, $this->getWrapperElement($node));
        }

        // Default behavior: repeat the directive element itself
        return $this->compileWithoutWrapper($node);
    }

    /**
     * Compile with wrapper pattern - element is container, children repeat inside
     *
     * @param \Sugar\Ast\DirectiveNode $node The while directive node
     * @param \Sugar\Ast\ElementNode $wrapper The wrapper element
     * @return array<\Sugar\Ast\Node>
     */
    private function compileWithWrapper(Node $node, ElementNode $wrapper): array
    {
        $parts = [];

        // Create wrapper element with loop content inside
        $parts[] = new ElementNode(
            tag: $wrapper->tag,
            attributes: $wrapper->attributes,
            children: [
                // Opening while
                new RawPhpNode('while (' . $node->expression . '):', $node->line, $node->column),
                // Wrapper's children (repeated content)
                ...$wrapper->children,
                // Closing endwhile
                new RawPhpNode('endwhile;', $node->line, $node->column),
            ],
            selfClosing: $wrapper->selfClosing,
            line: $wrapper->line,
            column: $wrapper->column,
        );

        return $parts;
    }

    /**
     * Compile without wrapper - repeat the directive element itself
     *
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    private function compileWithoutWrapper(Node $node): array
    {
        $parts = [];

        // Opening while
        $parts[] = new RawPhpNode('while (' . $node->expression . '):', $node->line, $node->column);

        // Children nodes (loop body)
        array_push($parts, ...$node->children);

        // Closing endwhile
        $parts[] = new RawPhpNode('endwhile;', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
