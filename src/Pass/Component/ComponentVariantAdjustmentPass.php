<?php
declare(strict_types=1);

namespace Sugar\Pass\Component;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\Node;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Pass\Component\Helper\ComponentAttributeOverrideHelper;
use Sugar\Pass\Component\Helper\SlotResolver;

/**
 * Applies component-specific adjustments during compilation.
 *
 * This pass runs after component expansion to:
 * - Disable escaping for slot variables so slot HTML is not double-escaped.
 * - Apply attribute overrides on the component root once merged props are known.
 */
final class ComponentVariantAdjustmentPass implements AstPassInterface
{
    /**
     * @param array<string> $slotVars
     */
    public function __construct(
        private readonly array $slotVars,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            SlotResolver::disableEscaping($node, $this->slotVars);
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            ComponentAttributeOverrideHelper::apply($node, '$__sugar_attrs');
        }

        return NodeAction::none();
    }
}
