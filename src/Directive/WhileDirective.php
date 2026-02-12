<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\Trait\WrapperModeTrait;
use Sugar\Enum\DirectiveType;

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
readonly class WhileDirective implements DirectiveInterface
{
    use WrapperModeTrait;

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
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
    protected function compileWithWrapper(Node $node, ElementNode $wrapper): array
    {
        return [NodeCloner::withChildren($wrapper, [
            // Opening while
            $this->rawNode('while (' . $node->expression . '):', $node),
            // Wrapper's children (repeated content)
            ...$wrapper->children,
            // Closing endwhile
            $this->rawNode('endwhile;', $node),
        ])];
    }

    /**
     * Compile without wrapper - repeat the directive element itself
     *
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    protected function compileWithoutWrapper(Node $node): array
    {
        $parts = [];

        // Opening while
        $parts[] = $this->rawNode('while (' . $node->expression . '):', $node);

        // Children nodes (loop body)
        array_push($parts, ...$node->children);

        // Closing endwhile
        $parts[] = $this->rawNode('endwhile;', $node);

        return $parts;
    }

    /**
     * Build a RawPhpNode that inherits the directive's template path.
     */
    private function rawNode(string $code, Node $origin): RawPhpNode
    {
        $rawNode = new RawPhpNode($code, $origin->line, $origin->column);
        $rawNode->inheritTemplatePathFrom($origin);

        return $rawNode;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
