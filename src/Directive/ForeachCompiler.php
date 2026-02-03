<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for foreach directive
 *
 * Transforms s:foreach directives into PHP foreach loops with automatic
 * $loop variable injection for loop metadata.
 *
 * Example:
 * ```
 * <li s:foreach="$items as $item"><?= $item ?></li>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php
 * $__loopStack[] = $loop ?? null;
 * $loop = new \Sugar\Runtime\LoopMetadata($items, end($__loopStack));
 * foreach ($items as $item):
 *     ?>
 * <li><?= $item ?></li>
 * <?php
 *     $loop->next();
 * endforeach;
 * $loop = array_pop($__loopStack);
 * ?>
 * ```
 */
final readonly class ForeachCompiler implements DirectiveCompilerInterface
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

        return $parts;
    }

    /**
     * Extract collection variable from foreach expression
     *
     * Examples:
     * - "$items as $item" -> "$items"
     * - "$users as $key => $user" -> "$users"
     * - "range(1, 10) as $i" -> "range(1, 10)"
     *
     * @param string $expression Foreach expression
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
}
