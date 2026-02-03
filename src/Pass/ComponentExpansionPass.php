<?php
declare(strict_types=1);

namespace Sugar\Pass;

use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Parser\Parser;
use Sugar\TemplateInheritance\TemplateLoaderInterface;

/**
 * Expands component invocations into their template content
 *
 * Replaces ComponentNode instances with their actual template content,
 * injecting slots and attributes as variables.
 */
final readonly class ComponentExpansionPass
{
    /**
     * Constructor
     *
     * @param \Sugar\TemplateInheritance\TemplateLoaderInterface $loader Template loader for loading components
     * @param \Sugar\Parser\Parser $parser Parser for parsing component templates
     * @param string $directivePrefix Directive prefix (e.g., 's' for s:if, s:class)
     */
    public function __construct(
        private TemplateLoaderInterface $loader,
        private Parser $parser,
        private string $directivePrefix = 's',
    ) {
    }

    /**
     * Process document and expand all components
     *
     * @param \Sugar\Ast\DocumentNode $document Document to process
     * @return \Sugar\Ast\DocumentNode Processed document with expanded components
     * @throws \Sugar\Exception\ComponentNotFoundException If component template not found
     */
    public function process(DocumentNode $document): DocumentNode
    {
        $expandedChildren = $this->expandNodes($document->children);

        return new DocumentNode($expandedChildren);
    }

    /**
     * Expand nodes, replacing ComponentNode with expanded content
     *
     * @param array<\Sugar\Ast\Node> $nodes Nodes to process
     * @return array<\Sugar\Ast\Node> Processed nodes
     */
    private function expandNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if ($node instanceof ComponentNode) {
                // Expand component
                $expanded = $this->expandComponent($node);
                $result = array_merge($result, $expanded);
            } elseif ($node instanceof ElementNode) {
                // Process children recursively
                $node->children = $this->expandNodes($node->children);
                $result[] = $node;
            } elseif (property_exists($node, 'children') && is_array($node->children)) {
                // Process any other node with children
                $node->children = $this->expandNodes($node->children);
                $result[] = $node;
            } else {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * Expand a single component node
     *
     * @param \Sugar\Ast\ComponentNode $component Component to expand
     * @return array<\Sugar\Ast\Node> Expanded nodes
     */
    private function expandComponent(ComponentNode $component): array
    {
        // Load component template
        $templateContent = $this->loader->loadComponent($component->name);

        // Parse component template
        $templateAst = $this->parser->parse($templateContent);

        // Extract slots from component usage BEFORE expanding (so we can detect s:slot attributes)
        $slots = $this->extractSlots($component->children);
        $defaultSlot = $this->extractDefaultSlot($component->children);

        // NOW expand any nested components in the slot contents
        $expandedSlots = [];
        foreach ($slots as $name => $nodes) {
            $expandedSlots[$name] = $this->expandNodes($nodes);
        }
        $expandedDefaultSlot = $this->expandNodes($defaultSlot);

        // Wrap component template with variable injections
        $wrappedTemplate = $this->wrapWithVariables(
            $templateAst,
            $component->attributes,
            $expandedSlots,
            $expandedDefaultSlot,
        );

        // Recursively expand any nested components in the wrapped template itself
        return $this->expandNodes($wrappedTemplate->children);
    }

    /**
     * Extract named slots from component children
     *
     * @param array<\Sugar\Ast\Node> $children Component children
     * @return array<string, array<\Sugar\Ast\Node>> Slot name => nodes
     */
    private function extractSlots(array $children): array
    {
        $slots = [];

        foreach ($children as $child) {
            if ($child instanceof ElementNode) {
                // Check for s:slot attribute
                $slotName = null;
                $slotAttrIndex = null;

                foreach ($child->attributes as $index => $attr) {
                    if ($attr->name === $this->directivePrefix . ':slot') {
                        $slotName = $attr->value;
                        $slotAttrIndex = (int)$index;
                        break;
                    }
                }

                if ($slotName !== null && is_string($slotName) && $slotAttrIndex !== null) {
                    // Remove s:slot attribute and use element as slot content
                    $clonedElement = clone $child;
                    array_splice($clonedElement->attributes, $slotAttrIndex, 1);
                    $slots[$slotName] = [$clonedElement];
                }
            }
        }

        return $slots;
    }

    /**
     * Extract default slot (children without s:slot attribute)
     *
     * @param array<\Sugar\Ast\Node> $children Component children
     * @return array<\Sugar\Ast\Node> Default slot nodes
     */
    private function extractDefaultSlot(array $children): array
    {
        $defaultSlot = [];

        foreach ($children as $child) {
            $hasSlotAttr = false;

            if ($child instanceof ElementNode) {
                // Check if this element has s:slot attribute
                foreach ($child->attributes as $attr) {
                    if ($attr->name === $this->directivePrefix . ':slot') {
                        $hasSlotAttr = true;
                        break;
                    }
                }
            }

            // Add to default slot if no s:slot attribute
            if (!$hasSlotAttr) {
                $defaultSlot[] = $child;
            }
        }

        return $defaultSlot;
    }

    /**
     * Wrap template with PHP code that injects variables
     *
     * @param \Sugar\Ast\DocumentNode $template Component template AST
     * @param array<\Sugar\Ast\AttributeNode> $attributes Component attributes
     * @param array<string, array<\Sugar\Ast\Node>> $namedSlots Named slots
     * @param array<\Sugar\Ast\Node> $defaultSlot Default slot content
     * @return \Sugar\Ast\DocumentNode Wrapped template
     */
    private function wrapWithVariables(
        DocumentNode $template,
        array $attributes,
        array $namedSlots,
        array $defaultSlot,
    ): DocumentNode {
        $injectionCode = '';

        // Inject component attributes as variables (skip directive attributes like s:if)
        foreach ($attributes as $attr) {
            // Skip directive attributes (s:if, s:class, etc.) - they're handled by directive passes
            if (str_starts_with($attr->name, $this->directivePrefix . ':')) {
                continue;
            }

            if (is_string($attr->value)) {
                $injectionCode .= sprintf(
                    '$%s = %s;' . "\n",
                    $attr->name,
                    var_export($attr->value, true),
                );
            }
        }

        // Inject default slot as $slot
        $injectionCode .= '$slot = ';
        if (empty($defaultSlot)) {
            $injectionCode .= "'';\n";
        } else {
            $injectionCode .= $this->nodesToPhpString($defaultSlot) . ";\n";
        }

        // Inject named slots as variables
        foreach ($namedSlots as $name => $nodes) {
            $injectionCode .= sprintf('$%s = %s;' . "\n", $name, $this->nodesToPhpString($nodes));
        }

        // Prepend injection code to template (without PHP tags - RawPhpNode adds them)
        $injectionNode = new RawPhpNode(trim($injectionCode), 0, 0);
        $newChildren = array_merge([$injectionNode], $template->children);

        return new DocumentNode($newChildren);
    }

    /**
     * Convert nodes to PHP string representation for injection
     *
     * @param array<\Sugar\Ast\Node> $nodes Nodes to convert
     * @return string PHP string representation
     */
    private function nodesToPhpString(array $nodes): string
    {
        if (empty($nodes)) {
            return "''";
        }

        // For now, use a simple approach: render nodes and capture output
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
     * Convert single node to PHP expression
     *
     * @param \Sugar\Ast\Node $node Node to convert
     * @return string PHP expression
     */
    private function nodeToPhpExpression(Node $node): string
    {
        if ($node instanceof OutputNode) {
            return '(' . $node->expression . ')';
        }

        // For other nodes, we need to render them as strings
        // This is a simplified version - in production we'd use a proper renderer
        return var_export($this->nodeToString($node), true);
    }

    /**
     * Convert node to string (simplified rendering)
     *
     * @param \Sugar\Ast\Node $node Node to convert
     * @return string String representation
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
                if ($attr->value !== null) {
                    if ($attr->value instanceof OutputNode) {
                        $html .= '="<?= ' . $attr->value->expression . ' ?>"';
                    } else {
                        $html .= '="' . $attr->value . '"';
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
}
