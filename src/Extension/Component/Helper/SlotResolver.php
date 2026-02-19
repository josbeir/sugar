<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Helper;

use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Ast\TextNode;

/**
 * Resolves component slots and builds slot expressions for rendering.
 *
 * Extracts named and default slots from a component invocation, then builds
 * PHP expressions used to render slot content at runtime. Also disables
 * escaping for slot variables to avoid double-escaping rendered HTML.
 */
final class SlotResolver
{
    /**
     * Disable escaping for OutputNodes that reference slot variables.
     *
     * @param \Sugar\Core\Ast\Node $node Node to process
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

        if (
            $node instanceof ElementNode
            || $node instanceof FragmentNode
            || $node instanceof DocumentNode
            || $node instanceof DirectiveNode
            || $node instanceof ComponentNode
        ) {
            foreach ($node->children as $child) {
                self::disableEscaping($child, $slotVars);
            }
        }

        if ($node instanceof ElementNode) {
            foreach ($node->attributes as $attr) {
                if ($attr->value->isOutput()) {
                    $output = $attr->value->output;
                    if ($output instanceof OutputNode) {
                        self::disableEscaping($output, $slotVars);
                    }

                    continue;
                }

                $parts = $attr->value->toParts();
                if ($parts === null) {
                    continue;
                }

                foreach ($parts as $part) {
                    if ($part instanceof OutputNode) {
                        self::disableEscaping($part, $slotVars);
                    }
                }
            }
        }
    }

    /**
     * @param string $slotAttrName Full slot attribute name (e.g., 's:slot')
     */
    public function __construct(
        private readonly string $slotAttrName,
    ) {
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    public function extract(array $children): ComponentSlots
    {
        $namedSlots = $this->extractNamedSlots($children);
        $defaultSlot = $this->extractDefaultSlot($children);

        return new ComponentSlots($namedSlots, $defaultSlot);
    }

    /**
     * @return array<string>
     */
    public function buildSlotVars(ComponentSlots $slots): array
    {
        return array_merge(['slot'], array_keys($slots->namedSlots));
    }

    /**
     * @return array<string>
     */
    public function buildSlotItems(ComponentSlots $slots): array
    {
        $items = [];

        if ($slots->defaultSlot === []) {
            $items[] = "'slot' => ''";
        } else {
            $items[] = sprintf("'slot' => %s", $this->nodesToPhpString($slots->defaultSlot));
        }

        foreach ($slots->namedSlots as $name => $nodes) {
            $items[] = sprintf("'%s' => %s", $name, $this->nodesToPhpString($nodes));
        }

        return $items;
    }

    /**
     * Build a runtime array expression for slot content.
     */
    public function buildSlotsExpression(ComponentSlots $slots): string
    {
        $items = $this->buildSlotItems($slots);

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     * @return array<string, array<\Sugar\Core\Ast\Node>>
     */
    private function extractNamedSlots(array $children): array
    {
        $slots = [];

        foreach ($children as $child) {
            if (!$child instanceof ElementNode && !$child instanceof FragmentNode) {
                continue;
            }

            $slotInfo = $this->findSlotAttribute($child->attributes);
            if ($slotInfo === null) {
                continue;
            }

            [$slotName, $slotAttrIndex] = $slotInfo;

            if ($child instanceof FragmentNode) {
                $slots[$slotName] = $child->children;
                continue;
            }

            $clonedElement = clone $child;
            array_splice($clonedElement->attributes, $slotAttrIndex, 1);
            $slots[$slotName] = [$clonedElement];
        }

        return $slots;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function extractDefaultSlot(array $children): array
    {
        $defaultSlot = [];

        foreach ($children as $child) {
            $isSlottedElement = ($child instanceof ElementNode || $child instanceof FragmentNode)
                && $this->findSlotAttribute($child->attributes) !== null;

            if ($isSlottedElement) {
                continue;
            }

            $defaultSlot[] = $child;
        }

        return $defaultSlot;
    }

    /**
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     * @return array{string, int}|null
     */
    private function findSlotAttribute(array $attributes): ?array
    {
        $result = AttributeHelper::findAttributeWithIndex($attributes, $this->slotAttrName);

        if ($result !== null) {
            [$attr, $index] = $result;
            if ($attr->value->isStatic()) {
                return [$attr->value->static ?? '', $index];
            }
        }

        return null;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     */
    private function nodesToPhpString(array $nodes): string
    {
        if ($nodes === []) {
            return "''";
        }

        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = $this->nodeToPhpExpression($node);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return implode(' . ', $parts);
    }

    /**
     * Convert a single node into a PHP expression string.
     *
     * For nodes containing dynamic children (OutputNode, RuntimeCallNode),
     * builds a concatenated PHP expression rather than embedding PHP
     * tags inside a string literal.
     */
    private function nodeToPhpExpression(Node $node): string
    {
        if ($node instanceof OutputNode) {
            return '(' . $node->expression . ')';
        }

        if ($node instanceof RuntimeCallNode) {
            return '(' . $node->callableExpression . '(' . implode(', ', $node->arguments) . '))';
        }

        if ($node instanceof ElementNode && $this->hasDynamicDescendants($node)) {
            return $this->elementToConcatenatedExpression($node);
        }

        return var_export($this->nodeToString($node), true);
    }

    /**
     * Check if an element has any dynamic descendant nodes.
     */
    private function hasDynamicDescendants(ElementNode $node): bool
    {
        foreach ($node->children as $child) {
            if ($child instanceof OutputNode || $child instanceof RuntimeCallNode) {
                return true;
            }

            if ($child instanceof ElementNode && $this->hasDynamicDescendants($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert an element with dynamic children to a concatenated PHP expression.
     *
     * Builds an expression like: '<div>' . (dynamicExpr) . '</div>'
     */
    private function elementToConcatenatedExpression(ElementNode $node): string
    {
        $openTag = '<' . $node->tag;
        foreach ($node->attributes as $attr) {
            $openTag .= ' ' . $attr->name;
            if (!$attr->value->isBoolean()) {
                $parts = $attr->value->toParts() ?? [];
                $attrValue = '';
                foreach ($parts as $part) {
                    $attrValue .= $part instanceof OutputNode
                        ? $part->expression
                        : (string)$part;
                }

                $openTag .= '="' . $attrValue . '"';
            }
        }

        $openTag .= '>';

        $expressions = [var_export($openTag, true)];

        foreach ($node->children as $child) {
            $expressions[] = $this->nodeToPhpExpression($child);
        }

        if (!$node->selfClosing) {
            $expressions[] = var_export('</' . $node->tag . '>', true);
        }

        return implode(' . ', $expressions);
    }

    /**
     * Render a node to a simplified HTML string representation.
     *
     * Only works correctly for static nodes. Dynamic nodes (OutputNode,
     * RuntimeCallNode) use placeholder syntax that won't execute in strings.
     */
    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode || $node instanceof RawBodyNode) {
            return $node->content;
        }

        if ($node instanceof ElementNode) {
            $html = '<' . $node->tag;
            foreach ($node->attributes as $attr) {
                $html .= ' ' . $attr->name;
                if (!$attr->value->isBoolean()) {
                    $parts = $attr->value->toParts() ?? [];
                    if (count($parts) > 1) {
                        $html .= '="';
                        foreach ($parts as $part) {
                            if ($part instanceof OutputNode) {
                                $html .= '<?= ' . $part->expression . ' ?>';
                                continue;
                            }

                            $html .= $part;
                        }

                        $html .= '"';
                    } else {
                        $part = $parts[0] ?? '';
                        if ($part instanceof OutputNode) {
                            $html .= '="<?= ' . $part->expression . ' ?>"';
                        } else {
                            $html .= '="' . $part . '"';
                        }
                    }
                }
            }

            $html .= '>';

            foreach ($node->children as $child) {
                $html .= $this->nodeToString($child);
            }

            if (!$node->selfClosing) {
                $html .= '</' . $node->tag . '>';
            }

            return $html;
        }

        if ($node instanceof OutputNode) {
            return '<?= ' . $node->expression . ' ?>';
        }

        if ($node instanceof RuntimeCallNode) {
            return '<?= ' . $node->callableExpression . '(' . implode(', ', $node->arguments) . ') ?>';
        }

        return '';
    }

    /**
     * Check if a PHP expression references a specific variable.
     */
    private static function expressionReferencesVariable(string $expression, string $varName): bool
    {
        $pattern = '/\$' . preg_quote($varName, '/') . '(?![a-zA-Z0-9_])/';

        return (bool)preg_match($pattern, $expression);
    }
}
