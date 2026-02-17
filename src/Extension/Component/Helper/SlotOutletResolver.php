<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Helper;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Exception\SyntaxException;

/**
 * Resolves slot outlet declarations inside component templates.
 *
 * Slot outlets allow component templates to declare fallback content using
 * `s:slot` attributes on elements or fragments. When caller-provided slot
 * content exists, it replaces the outlet content.
 */
final readonly class SlotOutletResolver
{
    /**
     * @param string $slotAttributeName Full slot attribute name (e.g. `s:slot`)
     */
    public function __construct(private string $slotAttributeName)
    {
    }

    /**
     * Apply caller-provided slots to component template outlets.
     */
    public function apply(
        DocumentNode $document,
        ComponentSlots $slots,
        ?CompilationContext $context = null,
    ): DocumentNode {
        $children = $this->rewriteNodes($document->children, $slots, $context);

        return new DocumentNode(
            children: $children,
            line: $document->line,
            column: $document->column,
        );
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function rewriteNodes(
        array $nodes,
        ComponentSlots $slots,
        ?CompilationContext $context,
    ): array {
        $resolved = [];

        foreach ($nodes as $node) {
            if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
                $resolved[] = $node;
                continue;
            }

            $slotInfo = $this->findSlotAttribute($node, $context);

            if ($slotInfo === null) {
                $children = $this->rewriteNodes($node->children, $slots, $context);
                $resolved[] = $node instanceof ElementNode
                    ? NodeCloner::withChildren($node, $children)
                    : NodeCloner::fragmentWithChildren($node, $children);
                continue;
            }

            [$slotName, $slotAttr] = $slotInfo;
            $fallbackChildren = $this->rewriteNodes($node->children, $slots, $context);
            $slotContent = $this->resolveSlotContent($slotName, $slots);
            $replacementChildren = $slotContent !== null
                ? $this->rewriteNodes(NodeCloner::cloneNodes($slotContent), $slots, $context)
                : $fallbackChildren;

            if ($node instanceof FragmentNode) {
                array_push($resolved, ...$replacementChildren);
                continue;
            }

            $cleanElement = $this->removeSlotAttribute($node, $slotAttr);
            $resolved[] = NodeCloner::withChildren($cleanElement, $replacementChildren);
        }

        return $resolved;
    }

    /**
     * @return array{string, \Sugar\Core\Ast\AttributeNode}|null
     */
    private function findSlotAttribute(
        ElementNode|FragmentNode $node,
        ?CompilationContext $context,
    ): ?array {
        $result = AttributeHelper::findAttributeWithIndex($node->attributes, $this->slotAttributeName);
        if ($result === null) {
            return null;
        }

        [$attribute] = $result;

        if ($attribute->value->isBoolean()) {
            return ['slot', $attribute];
        }

        if (!$attribute->value->isStatic()) {
            $message = sprintf('%s outlet value must be a static slot name.', $this->slotAttributeName);
            if ($context instanceof CompilationContext) {
                throw $context->createSyntaxExceptionForAttribute($message, $attribute);
            }

            throw new SyntaxException($message);
        }

        $name = trim($attribute->value->static ?? '');
        if ($name === '') {
            $name = 'slot';
        }

        return [$name, $attribute];
    }

    /**
     * @return array<\Sugar\Core\Ast\Node>|null
     */
    private function resolveSlotContent(string $slotName, ComponentSlots $slots): ?array
    {
        if ($slotName === 'slot') {
            return $slots->defaultSlot !== [] ? $slots->defaultSlot : null;
        }

        return $slots->namedSlots[$slotName] ?? null;
    }

    /**
     * Remove outlet attribute from element before output.
     */
    private function removeSlotAttribute(ElementNode $element, AttributeNode $slotAttribute): ElementNode
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if ($attribute === $slotAttribute) {
                continue;
            }

            $attributes[] = $attribute;
        }

        return NodeCloner::withAttributesAndChildren($element, $attributes, $element->children);
    }
}
