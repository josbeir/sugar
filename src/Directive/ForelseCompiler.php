<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\PairedDirectiveCompilerInterface;
use Sugar\Runtime\EmptyHelper;

/**
 * Compiler for forelse directive
 *
 * Transforms s:forelse directives into PHP foreach loops with optional
 * else clause when the collection is empty. Must be paired with s:empty
 * directive on a sibling element.
 *
 * Extends ForeachCompiler and adds if/else logic around the loop.
 *
 * Supports two modes (inherited from ForeachCompiler):
 * 1. **Wrapper mode**: Element has child elements - acts as container
 * 2. **Repeat mode**: Element is leaf - repeats itself
 *
 * Wrapper mode example:
 * ```
 * <ul s:forelse="$items as $item">
 *     <li><?= $item ?></li>
 * </ul>
 * <div s:empty>
 *     No items found
 * </div>
 * ```
 * Compiles to:
 * ```php
 * <?php if (!empty($items)): ?>
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
 * <?php else: ?>
 * <div>
 *     No items found
 * </div>
 * <?php endif; ?>
 * ```
 *
 * Repeat mode example:
 * ```
 * <li s:forelse="$items as $item"><?= $item ?></li>
 * <div s:empty>No items found</div>
 * ```
 * Compiles to:
 * ```php
 * <?php
 * if (!empty($items)):
 *     $__loopStack[] = $loop ?? null;
 *     $loop = new \Sugar\Runtime\LoopMetadata($items, end($__loopStack));
 *     foreach ($items as $item):
 * ?>
 * <li><?= $item ?></li>
 * <?php
 *         $loop->next();
 *     endforeach;
 *     $loop = array_pop($__loopStack);
 * else: ?>
 * <div>No items found</div>
 * <?php endif; ?>
 * ```
 */
class ForelseCompiler extends ForeachCompiler implements PairedDirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // Check if we have a paired s:empty sibling
        $emptyNode = $node->getPairedSibling();

        // If no paired empty clause, behave exactly like foreach
        if ($emptyNode === null) {
            return parent::compile($node, $context);
        }

        // Otherwise, wrap the foreach logic in if/else
        $parts = [];
        $collection = $this->extractCollection($node->expression);

        // Opening if (!empty($collection))
        $parts[] = new RawPhpNode(
            sprintf('if (!' . EmptyHelper::class . '::isEmpty(%s)):', $collection),
            $node->line,
            $node->column,
        );

        // Compile the foreach loop (wrapper or repeat mode)
        array_push($parts, ...parent::compile($node, $context));

        // Else clause
        $parts[] = new RawPhpNode('else:', $node->line, $node->column);

        // Empty directive's children (fallback content)
        array_push($parts, ...$emptyNode->children);

        // Closing endif
        $parts[] = new RawPhpNode('endif;', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getPairingDirective(): string
    {
        return 'empty';
    }
}
