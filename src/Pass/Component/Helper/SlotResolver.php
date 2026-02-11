<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;

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
     * @param array<\Sugar\Ast\Node> $children
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
     * @param array<\Sugar\Ast\Node> $children
     * @return array<string, array<\Sugar\Ast\Node>>
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
     * @param array<\Sugar\Ast\Node> $children
     * @return array<\Sugar\Ast\Node>
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
     * @param array<\Sugar\Ast\AttributeNode> $attributes
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
     * @param array<\Sugar\Ast\Node> $nodes
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
     */
    private function nodeToPhpExpression(Node $node): string
    {
        if ($node instanceof OutputNode) {
            return '(' . $node->expression . ')';
        }

        return var_export($this->nodeToString($node), true);
    }

    /**
     * Render a node to a simplified HTML string representation.
     */
    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
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
