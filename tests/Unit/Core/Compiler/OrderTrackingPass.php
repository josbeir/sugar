<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;

/**
 * Test helper pass that tracks execution order by recording a label when a TextNode is visited
 */
final class OrderTrackingPass implements AstPassInterface
{
    /**
     * @param array<string> $order Reference to the order tracking array
     * @param string $label Label to record when triggered
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten */
        private array &$order,
        private readonly string $label,
    ) {
    }

    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof TextNode) {
            $this->order[] = $this->label;
        }

        return NodeAction::none();
    }

    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
