<?php
declare(strict_types=1);

namespace Sugar\Pass\Component;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Pass\Component\Helper\ComponentAttributeOverrideHelper;
use Sugar\Pass\Middleware\AstMiddlewarePassInterface;
use Sugar\Pass\Middleware\NodeAction;
use Sugar\Pass\Middleware\WalkContext;

/**
 * Applies component variant adjustments without extra traversals.
 */
final class ComponentVariantAdjustmentPass implements AstMiddlewarePassInterface
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
    public function before(Node $node, WalkContext $context): NodeAction
    {
        if ($node instanceof OutputNode) {
            if ($this->shouldDisableEscaping($node)) {
                $node->escape = false;
            }

            return NodeAction::none();
        }

        if ($node instanceof ElementNode) {
            foreach ($node->attributes as $attr) {
                if ($attr->value instanceof OutputNode && $this->shouldDisableEscaping($attr->value)) {
                    $attr->value->escape = false;
                }
            }
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, WalkContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            ComponentAttributeOverrideHelper::apply($node, '$__sugar_attrs');
        }

        return NodeAction::none();
    }

    /**
     * Check if output escaping should be disabled for a slot variable reference.
     */
    private function shouldDisableEscaping(OutputNode $node): bool
    {
        foreach ($this->slotVars as $varName) {
            if ($this->expressionReferencesVariable($node->expression, $varName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a PHP expression references a specific variable.
     */
    private function expressionReferencesVariable(string $expression, string $varName): bool
    {
        $pattern = '/\$' . preg_quote($varName, '/') . '(?![a-zA-Z0-9_])/';

        return (bool)preg_match($pattern, $expression);
    }
}
