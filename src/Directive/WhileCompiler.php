<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for while directive
 *
 * Transforms s:while directives into PHP while loops.
 *
 * Example:
 * ```
 * <div s:while="$counter < 10">Counter: <?= $counter++ ?></div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php while ($counter < 10): ?>
 * <div>Counter: <?= $counter++ ?></div>
 * <?php endwhile; ?>
 * ```
 */
final readonly class WhileCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
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
