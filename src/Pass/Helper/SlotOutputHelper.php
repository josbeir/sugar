<?php
declare(strict_types=1);

namespace Sugar\Pass\Helper;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;

/**
 * Helper for disabling escaping on slot variable output nodes
 */
final class SlotOutputHelper
{
    /**
     * Disable escaping for OutputNodes that reference slot variables
     *
     * @param \Sugar\Ast\Node $node Node to process
     * @param array<string> $slotVars Slot variable names (without $)
     */
    public static function disableEscaping(Node $node, array $slotVars): void
    {
        if ($node instanceof OutputNode) {
            foreach ($slotVars as $varName) {
                if (self::expressionReferencesVariable($node->expression, $varName)) {
                    $node->escape = false;
                    break;
                }
            }
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof DocumentNode) {
            foreach ($node->children as $child) {
                self::disableEscaping($child, $slotVars);
            }
        }

        if ($node instanceof ElementNode) {
            foreach ($node->attributes as $attr) {
                if ($attr->value instanceof OutputNode) {
                    self::disableEscaping($attr->value, $slotVars);
                }
            }
        }
    }

    /**
     * Check if a PHP expression references a specific variable
     */
    private static function expressionReferencesVariable(string $expression, string $varName): bool
    {
        $pattern = '/\$' . preg_quote($varName, '/') . '(?![a-zA-Z0-9_])/';

        return (bool)preg_match($pattern, $expression);
    }
}
