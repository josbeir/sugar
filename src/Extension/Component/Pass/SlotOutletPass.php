<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Pass;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Extension\Component\Runtime\SlotOutletHelper;

/**
 * Transforms slot outlet declarations in component templates into conditional PHP.
 *
 * Finds elements and fragments with `s:slot` attributes and replaces them with
 * runtime conditional blocks that render caller-provided slot content through
 * `SlotOutletHelper::render()`, falling back to the outlet's
 * original children when no slot content is provided.
 *
 * For element outlets, the runtime call handles tag swapping and attribute merging
 * using caller metadata from `$__slot_meta`. For fragment outlets, slot content
 * is inserted directly without a wrapper element.
 *
 * This pass runs at PRE_DIRECTIVE_EXTRACTION priority to strip `s:slot` attributes
 * before they can be misinterpreted by directive processing passes.
 */
final readonly class SlotOutletPass implements AstPassInterface
{
    /**
     * @param string $slotAttributeName Full slot attribute name (e.g. `s:slot`)
     */
    public function __construct(
        private string $slotAttributeName,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof ElementNode) {
            return $this->tryTransformElementOutlet($node);
        }

        if ($node instanceof FragmentNode) {
            return $this->tryTransformFragmentOutlet($node);
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }

    /**
     * Transform an element with `s:slot` into a conditional outlet block.
     *
     * Generates: if slot content exists, render through SlotOutletHelper::render() with
     * tag swapping and attribute merging; otherwise render the original element
     * with its fallback children.
     */
    protected function tryTransformElementOutlet(ElementNode $node): NodeAction
    {
        $result = AttributeHelper::findAttributeWithIndex($node->attributes, $this->slotAttributeName);
        if ($result === null) {
            return NodeAction::none();
        }

        [$attribute, $index] = $result;
        $slotName = $this->resolveSlotName($attribute);
        $slotVar = '$' . $slotName;

        // Build outlet attributes array (without s:slot attribute)
        $outletAttrs = $node->attributes;
        array_splice($outletAttrs, $index, 1);
        $outletAttrsExpression = $this->buildOutletAttrsExpression($outletAttrs);

        // Build SlotOutletHelper::render() call
        $renderExpression = sprintf(
            '%s::render(%s, $__slot_meta[%s] ?? null, %s, %s)',
            SlotOutletHelper::class,
            $slotVar,
            var_export($slotName, true),
            var_export($node->tag, true),
            $outletAttrsExpression,
        );

        // Build the clean fallback element (without s:slot attribute)
        $cleanAttributes = $outletAttrs;
        $fallbackElement = new ElementNode(
            tag: $node->tag,
            attributes: $cleanAttributes,
            children: $node->children,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
        );
        $fallbackElement->setTemplatePath($node->getTemplatePath());

        // Generate conditional PHP nodes
        $ifNode = new RawPhpNode(
            code: sprintf("if ((%s ?? '') !== ''): ", $slotVar),
            line: $node->line,
            column: $node->column,
        );

        $outputNode = new OutputNode(
            expression: $renderExpression,
            escape: false,
            context: OutputContext::HTML,
            line: $node->line,
            column: $node->column,
        );

        $elseNode = new RawPhpNode(
            code: 'else: ',
            line: $node->line,
            column: $node->column,
        );

        $endifNode = new RawPhpNode(
            code: 'endif; ',
            line: $node->line,
            column: $node->column,
        );

        return NodeAction::replace([
            $ifNode,
            $outputNode,
            $elseNode,
            $fallbackElement,
            $endifNode,
        ]);
    }

    /**
     * Transform a fragment with `s:slot` into a conditional outlet block.
     *
     * Fragment outlets render slot content directly without a wrapper element,
     * falling back to the fragment's children when no content is provided.
     */
    protected function tryTransformFragmentOutlet(FragmentNode $node): NodeAction
    {
        $result = AttributeHelper::findAttributeWithIndex($node->attributes, $this->slotAttributeName);
        if ($result === null) {
            return NodeAction::none();
        }

        [$attribute] = $result;
        $slotName = $this->resolveSlotName($attribute);
        $slotVar = '$' . $slotName;

        $ifNode = new RawPhpNode(
            code: sprintf("if ((%s ?? '') !== ''): ", $slotVar),
            line: $node->line,
            column: $node->column,
        );

        $outputNode = new OutputNode(
            expression: $slotVar,
            escape: false,
            context: OutputContext::HTML,
            line: $node->line,
            column: $node->column,
        );

        $elseNode = new RawPhpNode(
            code: 'else: ',
            line: $node->line,
            column: $node->column,
        );

        $endifNode = new RawPhpNode(
            code: 'endif; ',
            line: $node->line,
            column: $node->column,
        );

        $replacement = [$ifNode, $outputNode, $elseNode];
        array_push($replacement, ...$node->children);
        $replacement[] = $endifNode;

        return NodeAction::replace($replacement);
    }

    /**
     * Resolve the slot name from an s:slot attribute.
     *
     * Boolean attributes (presence-only) map to the default slot.
     * Static values name the slot. Empty values fall back to the default slot.
     */
    protected function resolveSlotName(AttributeNode $attribute): string
    {
        if ($attribute->value->isBoolean()) {
            return 'slot';
        }

        $name = trim($attribute->value->static ?? '');

        return $name !== '' ? $name : 'slot';
    }

    /**
     * Build a PHP array expression from outlet element attributes.
     *
     * Converts the element's remaining attributes (after removing s:slot)
     * to a runtime array for `SlotOutletHelper::render()`.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Outlet attributes
     * @return string PHP array expression
     */
    protected function buildOutletAttrsExpression(array $attributes): string
    {
        if ($attributes === []) {
            return '[]';
        }

        $items = [];
        foreach ($attributes as $attr) {
            $key = var_export($attr->name, true);
            $value = AttributeHelper::attributeValueToPhpExpression(
                $attr->value,
                wrapOutputExpressions: true,
                booleanLiteral: 'null',
            );
            $items[] = $key . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }
}
