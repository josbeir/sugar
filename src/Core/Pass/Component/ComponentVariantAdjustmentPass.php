<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Component;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeTraverser;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Pass\Component\Helper\SlotResolver;
use Sugar\Core\Runtime\HtmlAttributeHelper;

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
            $this->applyRuntimeAttributeOverrides($node, '$__sugar_attrs');
        }

        return NodeAction::none();
    }

    /**
     * Apply runtime attribute overrides to the first root element in a template.
     */
    private function applyRuntimeAttributeOverrides(DocumentNode $template, string $attrsVar): void
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
                $existingExpr = $this->attributeValueExpression($attr);
                $expression = sprintf(
                    "%s::classNames([%s, %s['class'] ?? null])",
                    HtmlAttributeHelper::class,
                    $existingExpr,
                    $attrsVar,
                );

                $outputNode = new OutputNode(
                    expression: $expression,
                    escape: true,
                    context: OutputContext::HTML_ATTRIBUTE,
                    line: $attr->line,
                    column: $attr->column,
                );
                $outputNode->inheritTemplatePathFrom($attr);

                $newAttr = new AttributeNode(
                    name: 'class',
                    value: AttributeValue::output($outputNode),
                    line: $attr->line,
                    column: $attr->column,
                );
                $newAttr->inheritTemplatePathFrom($attr);
                $updatedAttributes[] = $newAttr;

                continue;
            }

            $existingExpr = $this->attributeValueExpression($attr);
            $expression = sprintf(
                "%s['%s'] ?? %s",
                $attrsVar,
                $attr->name,
                $existingExpr,
            );

            $outputNode = new OutputNode(
                expression: $expression,
                escape: true,
                context: OutputContext::HTML_ATTRIBUTE,
                line: $attr->line,
                column: $attr->column,
            );
            $outputNode->inheritTemplatePathFrom($attr);

            $newAttr = new AttributeNode(
                name: $attr->name,
                value: AttributeValue::output($outputNode),
                line: $attr->line,
                column: $attr->column,
            );
            $newAttr->inheritTemplatePathFrom($attr);
            $updatedAttributes[] = $newAttr;
        }

        $excludeExpression = $this->buildExcludeExpression(array_keys($existingNames));
        $spreadExpression = sprintf(
            '%s::spreadAttrs(array_diff_key(%s, %s))',
            HtmlAttributeHelper::class,
            $attrsVar,
            $excludeExpression,
        );

        $outputNode = new OutputNode(
            expression: $spreadExpression,
            escape: false,
            context: OutputContext::HTML_ATTRIBUTE,
            line: $rootElement->line,
            column: $rootElement->column,
        );
        $outputNode->inheritTemplatePathFrom($rootElement);

        $newAttr = new AttributeNode(
            name: '',
            value: AttributeValue::output($outputNode),
            line: $rootElement->line,
            column: $rootElement->column,
        );
        $newAttr->inheritTemplatePathFrom($rootElement);
        $updatedAttributes[] = $newAttr;

        $rootElement->attributes = $updatedAttributes;
    }

    /**
     * Convert attribute value to a PHP expression.
     */
    private function attributeValueExpression(AttributeNode $attr): string
    {
        return AttributeHelper::attributeValueToPhpExpression(
            $attr->value,
            wrapOutputExpressions: true,
            booleanLiteral: 'null',
        );
    }

    /**
     * Build array expression used for excluding runtime attributes.
     *
     * @param array<string> $names Attribute names to exclude
     */
    private function buildExcludeExpression(array $names): string
    {
        $pairs = [];
        foreach ($names as $name) {
            $pairs[] = var_export($name, true) . ' => true';
        }

        return '[' . implode(', ', $pairs) . ']';
    }
}
