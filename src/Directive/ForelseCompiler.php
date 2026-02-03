<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\PairedDirectiveCompilerInterface;

/**
 * Compiler for forelse directive
 *
 * Transforms s:forelse directives into PHP foreach loops with optional
 * else clause when the collection is empty. Must be paired with s:empty
 * directive on a sibling element.
 *
 * Example:
 * ```
 * <ul s:forelse="$items as $item">
 *     <li><?= $item ?></li>
 * </ul>
 * <div s:empty>
 *     No items found
 * </div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php
 * if (!empty($items)):
 *     $__loopStack[] = $loop ?? null;
 *     $loop = new \Sugar\Runtime\LoopMetadata($items, end($__loopStack));
 *     foreach ($items as $item):
 *         ?>
 * <ul>
 *     <li><?= $item ?></li>
 * </ul>
 * <?php
 *         $loop->next();
 *     endforeach;
 *     $loop = array_pop($__loopStack);
 * else:
 *     ?>
 * <div>
 *     No items found
 * </div>
 * <?php
 * endif;
 * ?>
 * ```
 */
final readonly class ForelseCompiler implements PairedDirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Extract collection variable from "as $var" or "as $key => $value"
        $collection = $this->extractCollection($node->expression);

        $parts = [];

        // If there's an elseChildren, wrap in if/else
        if ($node->elseChildren !== null) {
            // Opening if (!empty($collection))
            $parts[] = new RawPhpNode(
                sprintf('if (!empty(%s)):', $collection),
                $node->line,
                $node->column,
            );
        }

        // Push current $loop to stack (preserve parent loop)
        $parts[] = new RawPhpNode(
            '$__loopStack[] = $loop ?? null;',
            $node->line,
            $node->column,
        );

        // Create new LoopMetadata instance
        $parts[] = new RawPhpNode(
            sprintf(
                '$loop = new \Sugar\Runtime\LoopMetadata(%s, end($__loopStack));',
                $collection,
            ),
            $node->line,
            $node->column,
        );

        // Opening foreach
        $parts[] = new RawPhpNode(
            'foreach (' . $node->expression . '):',
            $node->line,
            $node->column,
        );

        // Children nodes (loop body)
        array_push($parts, ...$node->children);

        // Increment loop counter after each iteration
        $parts[] = new RawPhpNode('$loop->next();', $node->line, $node->column);

        // Closing endforeach
        $parts[] = new RawPhpNode('endforeach;', $node->line, $node->column);

        // Restore parent loop
        $parts[] = new RawPhpNode('$loop = array_pop($__loopStack);', $node->line, $node->column);

        // If there's an elseChildren, add else clause
        if ($node->elseChildren !== null) {
            // Else clause
            $parts[] = new RawPhpNode('else:', $node->line, $node->column);

            // Empty children (fallback content)
            array_push($parts, ...$node->elseChildren);

            // Closing endif
            $parts[] = new RawPhpNode('endif;', $node->line, $node->column);
        }

        return $parts;
    }

    /**
     * Extract collection variable from forelse expression
     *
     * Examples:
     * - "$items as $item" -> "$items"
     * - "$users as $key => $user" -> "$users"
     * - "range(1, 10) as $i" -> "range(1, 10)"
     *
     * @param string $expression Forelse expression
     * @return string Collection expression
     */
    private function extractCollection(string $expression): string
    {
        // Split on " as " and take the first part
        $parts = preg_split('/\s+as\s+/i', $expression, 2);

        return trim($parts[0] ?? $expression);
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * @inheritDoc
     */
    public function getPairingDirective(): string
    {
        return 'empty';
    }
}
