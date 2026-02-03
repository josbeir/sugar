<?php
declare(strict_types=1);

namespace Sugar\Directive;

use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for forelse directive (foreach with empty fallback)
 *
 * Transforms s:forelse with s:none fallback into if/foreach/else structure.
 * The s:none child (if present) is shown when the collection is empty.
 *
 * Example:
 * ```
 * <ul s:forelse="$users as $user">
 *     <li><?= $user->name ?></li>
 *     <li s:none>No users found</li>
 * </ul>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php if (!empty($users)): ?>
 * <ul>
 *     <?php foreach ($users as $user): ?>
 *         <li><?= $user->name ?></li>
 *     <?php endforeach; ?>
 * </ul>
 * <?php else: ?>
 * <ul>
 *     <li>No users found</li>
 * </ul>
 * <?php endif; ?>
 * ```
 */
final readonly class ForelseCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Extract collection and find none marker
        $collection = $this->extractCollection($node->expression);
        $noneMarker = null;
        $loopChildren = [];

        foreach ($node->children as $child) {
            if ($child instanceof DirectiveNode && $child->name === 'none') {
                if ($noneMarker instanceof DirectiveNode) {
                    throw new RuntimeException('Forelse can only have one none/empty marker');
                }

                $noneMarker = $child;
            } else {
                $loopChildren[] = $child;
            }
        }

        $parts = [];

        // If we have a none marker, wrap in if/else
        if ($noneMarker instanceof DirectiveNode) {
            // Opening if (!empty)
            $parts[] = new RawPhpNode(
                sprintf('if (!empty(%s)):', $collection),
                $node->line,
                $node->column,
            );
        }

        // Loop setup (similar to ForeachCompiler)
        $parts[] = new RawPhpNode(
            '$__loopStack[] = $loop ?? null;',
            $node->line,
            $node->column,
        );

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

        // Loop children (excluding none marker)
        array_push($parts, ...$loopChildren);

        // Increment loop counter
        $parts[] = new RawPhpNode('$loop->next();', $node->line, $node->column);

        // Closing endforeach
        $parts[] = new RawPhpNode('endforeach;', $node->line, $node->column);

        // Restore parent loop
        $parts[] = new RawPhpNode('$loop = array_pop($__loopStack);', $node->line, $node->column);

        // If we have a none marker, add else branch
        if ($noneMarker instanceof DirectiveNode) {
            $parts[] = new RawPhpNode('else:', $node->line, $node->column);
            array_push($parts, ...$noneMarker->children);
            $parts[] = new RawPhpNode('endif;', $node->line, $node->column);
        }

        return $parts;
    }

    /**
     * Extract collection variable from foreach expression
     *
     * Examples:
     * - "$items as $item" -> "$items"
     * - "$users as $key => $user" -> "$users"
     *
     * @param string $expression Foreach expression
     * @return string Collection expression
     */
    private function extractCollection(string $expression): string
    {
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
