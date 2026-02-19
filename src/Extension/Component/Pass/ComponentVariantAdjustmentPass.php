<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Pass;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeTraverser;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Runtime\HtmlAttributeHelper;
use Sugar\Extension\Component\Helper\SlotResolver;

/**
 * Applies component-specific adjustments during compilation.
 *
 * This pass runs in two phases to handle different compilation stages:
 * - **Directive phase** (DIRECTIVE_PAIRING priority): Intercepts root-level directive
 *   nodes to apply attribute overrides to stored elements (e.g., s:ifcontent), before
 *   directive compilation converts them to raw PHP.
 * - **Main phase** (POST_DIRECTIVE_COMPILATION priority): Disables escaping for slot
 *   variables and applies attribute overrides to direct root elements (including those
 *   resolved by template inheritance).
 */
final class ComponentVariantAdjustmentPass implements AstPassInterface
{
    /**
     * @param array<string> $slotVars Slot variable names for escaping adjustment
     * @param bool $directiveRootOnly When true, only processes directive-stored root elements
     */
    public function __construct(
        private readonly array $slotVars,
        private readonly bool $directiveRootOnly = false,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if (!$this->directiveRootOnly && $node instanceof DocumentNode) {
            SlotResolver::disableEscaping($node, $this->slotVars);
        }

        if ($this->directiveRootOnly && $node instanceof DirectiveNode && $context->parent instanceof DocumentNode) {
            $this->applyDirectiveStoredElementOverrides($node, '$__sugar_attrs');
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        if (!$this->directiveRootOnly && $node instanceof DocumentNode) {
            $this->applyRuntimeAttributeOverrides($node, '$__sugar_attrs');
        }

        return NodeAction::none();
    }

    /**
     * Apply attribute overrides to a root element stored inside a directive node.
     *
     * This handles the case where directives like s:ifcontent store the element
     * via setElementNode(). The element must be modified before directive compilation
     * converts it to raw PHP statements.
     */
    private function applyDirectiveStoredElementOverrides(DirectiveNode $directive, string $attrsVar): void
    {
        $rootElement = $this->findStoredElementRecursive($directive);
        if (!$rootElement instanceof ElementNode) {
            return;
        }

        $this->applyOverridesToElement($rootElement, $attrsVar);
    }

    /**
     * Apply runtime attribute overrides to the first root element in a template.
     *
     * Uses NodeTraverser to find direct ElementNode children after inheritance
     * compilation has resolved template extends/blocks.
     */
    private function applyRuntimeAttributeOverrides(DocumentNode $template, string $attrsVar): void
    {
        $rootElement = NodeTraverser::findRootElement($template);
        if (!$rootElement instanceof ElementNode) {
            return;
        }

        $this->applyOverridesToElement($rootElement, $attrsVar);
    }

    /**
     * Recursively search directive nodes for a stored element.
     */
    private function findStoredElementRecursive(DirectiveNode $directive): ?ElementNode
    {
        $storedElement = $directive->getElementNode();
        if ($storedElement instanceof ElementNode) {
            return $storedElement;
        }

        foreach ($directive->children as $child) {
            if ($child instanceof DirectiveNode) {
                return $this->findStoredElementRecursive($child);
            }
        }

        return null;
    }

    /**
     * Apply $__sugar_attrs overrides to an element's attributes.
     *
     * Merges runtime attribute overrides from the parent component invocation:
     * - class: Wraps existing value in classNames() with the override
     * - Other named attrs: Falls back to override if present
     * - Spread: Adds remaining unmatched attrs as spread attributes
     */
    private function applyOverridesToElement(ElementNode $rootElement, string $attrsVar): void
    {
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
