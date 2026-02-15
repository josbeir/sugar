<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Trait;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Trait for foreach-based loop directives (foreach, forelse)
 *
 * Provides shared functionality for:
 * - Loop setup/teardown (LoopMetadata, loop stack management)
 * - Collection extraction from foreach expressions
 */
trait ForeachLoopTrait
{
    /**
     * Extract collection variable from foreach expression
     *
     * Examples:
     * - "$items as $item" -> "$items"
     * - "$users as $key => $user" -> "$users"
     * - "range(1, 10) as $i" -> "range(1, 10)"
     *
     * @param string $expression Foreach/forelse expression
     * @return string Collection expression
     */
    protected function extractCollection(string $expression): string
    {
        // Split on " as " and take the first part
        $parts = preg_split('/\s+as\s+/i', $expression, 2);

        return trim($parts[0] ?? $expression);
    }

    /**
     * Create loop setup nodes (stack push, LoopMetadata creation)
     *
     * @param string $collection Collection variable/expression
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Core\Ast\RawPhpNode> Setup nodes
     */
    protected function createLoopSetup(string $collection, int $line, int $column): array
    {
        return [
            new RawPhpNode(
                '$__loopStack ??= [];',
                $line,
                $column,
            ),
            new RawPhpNode(
                '$__loopStack[] = $loop ?? null;',
                $line,
                $column,
            ),
            new RawPhpNode(
                sprintf(
                    '$loop = new \Sugar\Core\Runtime\LoopMetadata(%s, end($__loopStack));',
                    $collection,
                ),
                $line,
                $column,
            ),
        ];
    }

    /**
     * Create foreach opening node
     *
     * @param string $expression Full foreach expression
     * @param int $line Line number
     * @param int $column Column number
     * @return \Sugar\Core\Ast\RawPhpNode Foreach opening node
     */
    protected function createForeachOpening(string $expression, int $line, int $column): RawPhpNode
    {
        return new RawPhpNode(
            'foreach (' . $expression . '):',
            $line,
            $column,
        );
    }

    /**
     * Create loop teardown nodes (loop increment, endforeach, stack pop)
     *
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Core\Ast\RawPhpNode> Teardown nodes
     */
    protected function createLoopTeardown(int $line, int $column): array
    {
        return [
            new RawPhpNode('$loop->next();', $line, $column),
            new RawPhpNode('endforeach;', $line, $column),
            new RawPhpNode('$loop = array_pop($__loopStack);', $line, $column),
        ];
    }

    /**
     * Compile loop in wrapper mode - element is container, children repeat inside
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The loop directive node
     * @param \Sugar\Core\Ast\ElementNode $wrapper The wrapper element
     * @param array<\Sugar\Core\Ast\Node> $loopBody Loop body nodes (children to repeat)
     * @return \Sugar\Core\Ast\ElementNode Wrapper element with loop inside
     */
    protected function compileLoopWrapper(Node $node, ElementNode $wrapper, array $loopBody): ElementNode
    {
        $collection = $this->extractCollection($node->expression);

        // Build loop content
        $loopContent = [
            ...$this->createLoopSetup($collection, $node->line, $node->column),
            $this->createForeachOpening($node->expression, $node->line, $node->column),
            ...$loopBody,
            ...$this->createLoopTeardown($node->line, $node->column),
        ];

        // Create wrapper element with loop content inside
        return NodeCloner::withChildren($wrapper, $loopContent);
    }

    /**
     * Compile loop in repeat mode - directive element itself repeats
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The loop directive node
     * @return array<\Sugar\Core\Ast\Node> Loop nodes (setup, foreach, body, teardown)
     */
    protected function compileLoopRepeat(Node $node): array
    {
        $collection = $this->extractCollection($node->expression);

        return [
            ...$this->createLoopSetup($collection, $node->line, $node->column),
            $this->createForeachOpening($node->expression, $node->line, $node->column),
            ...$node->children,
            ...$this->createLoopTeardown($node->line, $node->column),
        ];
    }
}
