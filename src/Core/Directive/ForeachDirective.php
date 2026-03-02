<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Trait\ForeachLoopTrait;

/**
 * Compiler for foreach directive
 *
 * Transforms s:foreach directives into PHP foreach loops with automatic
 * $loop variable injection for loop metadata.
 *
 * The directive always repeats the host element.
 *
 * Example:
 * ```
 * <li s:foreach="$items as $item"><?= $item ?></li>
 * ```
 * Compiles to:
 * ```php
 * <?php
 * $__loopStack[] = $loop ?? null;
 * $loop = new \Sugar\Core\Runtime\LoopMetadata($items, end($__loopStack));
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
    use ForeachLoopTrait;

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $this->validateExpression($node, $context);

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
     * @param \Sugar\Core\Ast\DirectiveNode $node
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
