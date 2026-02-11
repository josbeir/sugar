<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;
use Sugar\Runtime\HtmlAttributeHelper;

/**
 * Applies runtime attribute overrides to a component root element.
 *
 * Merges runtime attributes into the first root element by:
 * - Combining classes via `HtmlAttributeHelper::classNames()`
 * - Falling back to existing attribute values when overrides are missing
 * - Spreading leftover runtime attributes onto the root element
 */
final class ComponentAttributeOverrideHelper
{
    /**
     * Apply runtime attribute overrides to the first root element in a template
     *
     * @param \Sugar\Ast\DocumentNode $template Component template AST
     * @param string $attrsVar PHP variable name for runtime attrs (e.g., '$__sugar_attrs')
     */
    public static function apply(DocumentNode $template, string $attrsVar): void
    {
        $rootElement = NodeTraverser::findRootElement($template);
        if (!$rootElement instanceof ElementNode) {
            return;
        }

        $existingNames = [];
        foreach ($rootElement->attributes as $attr) {
            if ($attr->name === '') {
                continue;
            }

            $existingNames[$attr->name] = true;
        }

        $updatedAttributes = [];

        foreach ($rootElement->attributes as $attr) {
            if ($attr->name === '') {
                $updatedAttributes[] = $attr;
                continue;
            }

            if ($attr->name === 'class') {
                $existingExpr = self::attributeValueExpression($attr);
                $expression = sprintf(
                    "%s::classNames([%s, %s['class'] ?? null])",
                    HtmlAttributeHelper::class,
                    $existingExpr,
                    $attrsVar,
                );

                $updatedAttributes[] = new AttributeNode(
                    name: 'class',
                    value: AttributeValue::output(new OutputNode(
                        expression: $expression,
                        escape: true,
                        context: OutputContext::HTML_ATTRIBUTE,
                        line: $attr->line,
                        column: $attr->column,
                    )),
                    line: $attr->line,
                    column: $attr->column,
                );

                continue;
            }

            $existingExpr = self::attributeValueExpression($attr);
            $expression = sprintf(
                "%s['%s'] ?? %s",
                $attrsVar,
                $attr->name,
                $existingExpr,
            );

            $updatedAttributes[] = new AttributeNode(
                name: $attr->name,
                value: AttributeValue::output(new OutputNode(
                    expression: $expression,
                    escape: true,
                    context: OutputContext::HTML_ATTRIBUTE,
                    line: $attr->line,
                    column: $attr->column,
                )),
                line: $attr->line,
                column: $attr->column,
            );
        }

        $excludeExpression = self::buildExcludeExpression(array_keys($existingNames));
        $spreadExpression = sprintf(
            '%s::spreadAttrs(array_diff_key(%s, %s))',
            HtmlAttributeHelper::class,
            $attrsVar,
            $excludeExpression,
        );

        $updatedAttributes[] = new AttributeNode(
            name: '',
            value: AttributeValue::output(new OutputNode(
                expression: $spreadExpression,
                escape: false,
                context: OutputContext::HTML_ATTRIBUTE,
                line: $rootElement->line,
                column: $rootElement->column,
            )),
            line: $rootElement->line,
            column: $rootElement->column,
        );

        $rootElement->attributes = $updatedAttributes;
    }

    /**
     * Convert attribute value to a PHP expression
     */
    private static function attributeValueExpression(AttributeNode $attr): string
    {
        if ($attr->value->isOutput()) {
            $output = $attr->value->output;
            if ($output instanceof OutputNode) {
                return '(' . $output->expression . ')';
            }
        }

        if ($attr->value->isBoolean()) {
            return 'null';
        }

        $parts = $attr->value->toParts() ?? [];
        if (count($parts) > 1) {
            $expressionParts = [];
            foreach ($parts as $part) {
                if ($part instanceof OutputNode) {
                    $expressionParts[] = '(' . $part->expression . ')';
                    continue;
                }

                $expressionParts[] = var_export($part, true);
            }

            return implode(' . ', $expressionParts);
        }

        $part = $parts[0] ?? '';
        if ($part instanceof OutputNode) {
            return '(' . $part->expression . ')';
        }

        return var_export($part, true);
    }

    /**
     * Build array expression used for excluding runtime attributes
     *
     * @param array<string> $names Attribute names to exclude
     */
    private static function buildExcludeExpression(array $names): string
    {
        $pairs = [];
        foreach ($names as $name) {
            $pairs[] = var_export($name, true) . ' => true';
        }

        return '[' . implode(', ', $pairs) . ']';
    }
}
