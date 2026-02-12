<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\Trait\ForeachLoopTrait;
use Sugar\Directive\Trait\WrapperModeTrait;
use Sugar\Enum\DirectiveType;

/**
 * Compiler for foreach directive
 *
 * Transforms s:foreach directives into PHP foreach loops with automatic
 * $loop variable injection for loop metadata.
 *
 * Supports two modes:
 * 1. **Wrapper mode**: Element has child elements - acts as container
 * 2. **Repeat mode**: Element is leaf - repeats itself
 *
 * Wrapper mode example:
 * ```
 * <ul s:foreach="$items as $item">
 *     <li><?= $item ?></li>
 * </ul>
 * ```
 * Compiles to:
 * ```php
 * <ul>
 * <?php
 * $__loopStack[] = $loop ?? null;
 * $loop = new \Sugar\Runtime\LoopMetadata($items, end($__loopStack));
 * foreach ($items as $item):
 * ?>
 *     <li><?= $item ?></li>
 * <?php
 *     $loop->next();
 * endforeach;
 * $loop = array_pop($__loopStack);
 * ?>
 * </ul>
 * ```
 *
 * Repeat mode example:
 * ```
 * <li s:foreach="$items as $item"><?= $item ?></li>
 * ```
 * Compiles to:
 * ```php
 * <?php
 * $__loopStack[] = $loop ?? null;
 * $loop = new \Sugar\Runtime\LoopMetadata($items, end($__loopStack));
 * foreach ($items as $item):
 * ?>
 * <li><?= $item ?></li>
 * <?php
 *     $loop->next();
 * endforeach;
 * $loop = array_pop($__loopStack);
 * ?>
 * ```
 */
class ForeachDirective implements DirectiveInterface
{
    use WrapperModeTrait;
    use ForeachLoopTrait;

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $this->validateExpression($node, $context);

        // Check if we should use wrapper mode (element as container)
        if ($this->shouldUseWrapperMode($node)) {
            $wrapper = $this->getWrapperElement($node);

            return [$this->compileLoopWrapper($node, $wrapper, $wrapper->children)];
        }

        // Default behavior: repeat the directive element itself
        return $this->compileLoopRepeat($node);
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * Ensure foreach-like directives have a valid iteration expression.
     *
     * @param \Sugar\Ast\DirectiveNode $node
     */
    protected function validateExpression(Node $node, CompilationContext $context): void
    {
        $expression = trim($node->expression);

        if ($expression === '' || !preg_match('/^.+\s+as\s+.+$/i', $expression)) {
            throw $context->createSyntaxExceptionForNode(
                sprintf('s:%s requires an expression like "$items as $item".', $node->name),
                $node,
            );
        }
    }
}
