<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\Interface\PairedDirectiveInterface;
use Sugar\Enum\DirectiveType;

/**
 * Compiler for s:try directive
 *
 * Wraps an element in a PHP try block with an optional s:finally sibling.
 *
 * Example:
 * ```
 * <div s:try>...</div>
 * <div s:finally>...</div>
 * ```
 */
final class TryDirective implements DirectiveInterface, PairedDirectiveInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [
            new RawPhpNode('try {', $node->line, $node->column),
        ];

        array_push($parts, ...$node->children);

        $paired = $node->getPairedSibling();
        if ($paired !== null) {
            $parts[] = new RawPhpNode('} finally {', $node->line, $node->column);
            array_push($parts, ...$paired->children);
            $parts[] = new RawPhpNode('}', $node->line, $node->column);

            return $parts;
        }

        $parts[] = new RawPhpNode('} catch (\\Throwable $__e) {', $node->line, $node->column);
        $parts[] = new RawPhpNode('return null;', $node->line, $node->column);

        $parts[] = new RawPhpNode('}', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getPairingDirective(): string
    {
        return 'finally';
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
