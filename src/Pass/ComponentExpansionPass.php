<?php
declare(strict_types=1);

namespace Sugar\Pass;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Parser\Parser;
use Sugar\TemplateInheritance\TemplateLoaderInterface;

/**
 * Expands component invocations into their template content
 *
 * Replaces ComponentNode instances with their actual template content,
 * injecting slots and attributes as variables.
 */
final readonly class ComponentExpansionPass implements PassInterface
{
    private DirectivePrefixHelper $prefixHelper;

    private string $slotAttrName;

    /**
     * Constructor
     *
     * @param \Sugar\TemplateInheritance\TemplateLoaderInterface $loader Template loader for loading components
     * @param \Sugar\Parser\Parser $parser Parser for parsing component templates
     * @param \Sugar\Extension\ExtensionRegistry $registry Extension registry for directive type checking
     * @param string $directivePrefix Directive prefix (e.g., 's' for s:if, s:class)
     */
    public function __construct(
        private TemplateLoaderInterface $loader,
        private Parser $parser,
        private ExtensionRegistry $registry,
        string $directivePrefix = 's',
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($directivePrefix);
        $this->slotAttrName = $directivePrefix . ':slot';
    }

    /**
     * Execute the pass: expand all component invocations
     *
     * @param \Sugar\Ast\DocumentNode $ast Document to process
     * @return \Sugar\Ast\DocumentNode Processed document with expanded components
     * @throws \Sugar\Exception\ComponentNotFoundException If component template not found
     */
    public function execute(DocumentNode $ast): DocumentNode
    {
        $expandedChildren = NodeTraverser::walk($ast->children, function (Node $node, callable $recurse) {
            if ($node instanceof ComponentNode) {
                // Expand component - return array of nodes
                return $this->expandComponent($node);
            }

            // Recurse into children for other nodes
            return $recurse($node);
        });

        return new DocumentNode($expandedChildren);
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

        // Categorize attributes: control flow, attribute directives, bindings, merge
        $categorized = $this->categorizeAttributes($component->attributes);

        // Find root element in component template for attribute merging
        $rootElement = $this->findRootElement($templateAst);

        // Merge non-binding attributes to root element
        if ($rootElement instanceof ElementNode) {
            $this->mergeAttributesToRoot(
                $rootElement,
                $categorized['merge'],
                $categorized['attributeDirectives'],
            );
        }

        // Extract slots from component usage BEFORE expanding (so we can detect s:slot attributes)
        $slots = $this->extractSlots($component->children);
        $defaultSlot = $this->extractDefaultSlot($component->children);

        // NOW expand any nested components in the slot contents
        $expandedSlots = [];
        foreach ($slots as $name => $nodes) {
            $expandedSlots[$name] = $this->expandNodes($nodes);
        }

        $expandedDefaultSlot = $this->expandNodes($defaultSlot);

        // Wrap component template with variable injections (only s-bind: attributes become variables)
        $wrappedTemplate = $this->wrapWithVariables(
            $templateAst,
            $categorized['componentBindings'],
            $expandedSlots,
            $expandedDefaultSlot,
        );

        // Recursively expand any nested components in the wrapped template itself
        $expandedContent = $this->expandNodes($wrappedTemplate->children);

        // If component has control flow directives, wrap in FragmentNode
        if ($categorized['controlFlow'] !== []) {
            return [new FragmentNode(
                attributes: $categorized['controlFlow'],
                children: $expandedContent,
                line: $component->line,
                column: $component->column,
            )];
        }

        return $expandedContent;
    }

    /**
     * Recursively expand components in a list of nodes
     *
     * @param array<\Sugar\Ast\Node> $nodes Nodes to process
     * @return array<\Sugar\Ast\Node> Processed nodes with expanded components
     */
    private function expandNodes(array $nodes): array
    {
        return NodeTraverser::walk($nodes, function (Node $node, callable $recurse) {
            if ($node instanceof ComponentNode) {
                return $this->expandComponent($node);
            }

            return $recurse($node);
        });
    }

    /**
     * Categorize component attributes into different types
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Component attributes
     * @return array{controlFlow: array<\Sugar\Ast\AttributeNode>, attributeDirectives: array<\Sugar\Ast\AttributeNode>, componentBindings: array<\Sugar\Ast\AttributeNode>, merge: array<\Sugar\Ast\AttributeNode>}
     */
    private function categorizeAttributes(array $attributes): array
    {
        $controlFlow = [];
        $attributeDirectives = [];
        $componentBindings = [];
        $mergeAttrs = [];

        foreach ($attributes as $attr) {
            $name = $attr->name;

            // Sugar directives: s:if, s:class, s:foreach
            if ($this->prefixHelper->isDirective($name)) {
                if ($this->isControlFlowDirective($name)) {
                    // Control flow: s:if, s:foreach, s:while
                    $controlFlow[] = $attr;
                } else {
                    // Attribute directives: s:class, s:spread
                    $attributeDirectives[] = $attr;
                }
            } elseif ($this->prefixHelper->isBinding($name)) {
                // Component bindings: s-bind:variant, s-bind:size
                // Remove prefix to get variable name
                $componentBindings[] = new AttributeNode(
                    $this->prefixHelper->stripBindingPrefix($name),
                    $attr->value,
                    $attr->line,
                    $attr->column,
                );
            } else {
                // Everything else merges: class, id, @click, x-show, data-*, v-if, hx-get
                $mergeAttrs[] = $attr;
            }
        }

        return [
            'controlFlow' => $controlFlow,
            'attributeDirectives' => $attributeDirectives,
            'componentBindings' => $componentBindings,
            'merge' => $mergeAttrs,
        ];
    }

    /**
     * Check if directive is a control flow directive
     *
     * @param string $directiveName Full directive name (e.g., 's:if', 's:foreach')
     */
    private function isControlFlowDirective(string $directiveName): bool
    {
        $name = $this->prefixHelper->stripPrefix($directiveName);

        if (!$this->registry->hasDirective($name)) {
            return false;
        }

        $compiler = $this->registry->getDirective($name);

        return $compiler->getType() === DirectiveType::CONTROL_FLOW;
    }

    /**
     * Find root element in component template
     *
     * @param \Sugar\Ast\DocumentNode $template Component template AST
     * @return \Sugar\Ast\ElementNode|null Root element or null if not found
     */
    private function findRootElement(DocumentNode $template): ?ElementNode
    {
        // Find first ElementNode child
        foreach ($template->children as $child) {
            if ($child instanceof ElementNode) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Merge attributes to root element
     *
     * @param \Sugar\Ast\ElementNode $rootElement Root element to merge into
     * @param array<\Sugar\Ast\AttributeNode> $mergeAttrs Regular attributes to merge
     * @param array<\Sugar\Ast\AttributeNode> $attributeDirectives Attribute directives (s:class, s:spread)
     */
    private function mergeAttributesToRoot(
        ElementNode $rootElement,
        array $mergeAttrs,
        array $attributeDirectives,
    ): void {
        // Build map of existing attributes
        $existingAttrs = [];
        foreach ($rootElement->attributes as $attr) {
            $existingAttrs[$attr->name] = $attr;
        }

        // Merge regular attributes
        foreach ($mergeAttrs as $attr) {
            if ($attr->name === 'class' && isset($existingAttrs['class'])) {
                // Special handling for class: append instead of replace
                $existingClass = $existingAttrs['class']->value;
                $newClass = $attr->value;

                // Both values must be strings for concatenation
                if (is_string($existingClass) && is_string($newClass)) {
                    $existingAttrs['class'] = new AttributeNode(
                        'class',
                        trim($existingClass . ' ' . $newClass),
                        $attr->line,
                        $attr->column,
                    );
                } else {
                    // If either is not a string (e.g., OutputNode), just override
                    $existingAttrs[$attr->name] = $attr;
                }
            } else {
                // Regular merge: usage overrides component
                $existingAttrs[$attr->name] = $attr;
            }
        }

        // Add attribute directives (s:class, s:spread)
        foreach ($attributeDirectives as $attr) {
            $existingAttrs[$attr->name] = $attr;
        }

        // Update root element attributes
        $rootElement->attributes = array_values($existingAttrs);
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
            // Handle both ElementNode and FragmentNode (s-template)
            if (!$child instanceof ElementNode && !$child instanceof FragmentNode) {
                continue;
            }

            $slotInfo = $this->findSlotAttribute($child->attributes);
            if ($slotInfo === null) {
                continue;
            }

            [$slotName, $slotAttrIndex] = $slotInfo;

            // For FragmentNode (s-template), use its children as slot content
            if ($child instanceof FragmentNode) {
                $slots[$slotName] = $child->children;
            } else {
                // For regular ElementNode, remove s:slot attribute and use the element itself
                $clonedElement = clone $child;
                array_splice($clonedElement->attributes, $slotAttrIndex, 1);
                $slots[$slotName] = [$clonedElement];
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
            // Check both ElementNode and FragmentNode for s:slot attribute
            $isSlottedElement = ($child instanceof ElementNode || $child instanceof FragmentNode) &&
                $this->findSlotAttribute($child->attributes) !== null;

            if ($isSlottedElement) {
                continue;
                // Has s:slot attribute, skip
            }

            // Add to default slot if no s:slot attribute
            $defaultSlot[] = $child;
        }

        return $defaultSlot;
    }

    /**
     * Find s:slot attribute in attributes array
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Attributes to search
     * @return array{string, int}|null [slotName, index] or null if not found
     */
    private function findSlotAttribute(array $attributes): ?array
    {
        $result = AttributeHelper::findAttributeWithIndex($attributes, $this->slotAttrName);

        if ($result !== null) {
            [$attr, $index] = $result;
            if (is_string($attr->value)) {
                return [$attr->value, $index];
            }
        }

        return null;
    }

    /**
     * Wrap template with PHP code that injects variables
     *
     * @param \Sugar\Ast\DocumentNode $template Component template AST
     * @param array<\Sugar\Ast\AttributeNode> $attributes Component attributes (non-directive)
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
        $arrayItems = [];

        // Add component attributes as array items
        foreach ($attributes as $attr) {
            if (is_string($attr->value)) {
                // s-bind values are PHP expressions, not literal strings
                $arrayItems[] = sprintf(
                    "'%s' => %s",
                    $attr->name,
                    $attr->value, // Use value as-is (it's a PHP expression)
                );
            }
        }

        // Collect all slot variable names
        $slotVars = ['slot'];
        foreach (array_keys($namedSlots) as $name) {
            $slotVars[] = $name;
        }

        // Add default slot
        if ($defaultSlot === []) {
            $arrayItems[] = "'slot' => ''";
        } else {
            $arrayItems[] = sprintf("'slot' => %s", $this->nodesToPhpString($defaultSlot));
        }

        // Add named slots
        foreach ($namedSlots as $name => $nodes) {
            $arrayItems[] = sprintf("'%s' => %s", $name, $this->nodesToPhpString($nodes));
        }

        // Automatically disable escaping for slot variable outputs in component template
        $this->processNodeForSlotWrapping($template, $slotVars);

        // Build closure with extract pattern for isolated scope
        $openingCode = '(function($__vars) { extract($__vars);';
        $closingCode = '})([' . implode(', ', $arrayItems) . ']);';

        // Wrap template in closure
        $openingNode = new RawPhpNode($openingCode, 0, 0);
        $closingNode = new RawPhpNode($closingCode, 0, 0);

        return new DocumentNode([
            $openingNode,
            ...$template->children,
            $closingNode,
        ]);
    }

    /**
     * Recursively process nodes to disable escaping for slot outputs
     *
     * Traverses the component template AST and sets escape=false for any OutputNode
     * referencing a slot variable (e.g., $slot, $header, $footer).
     *
     * Slots contain pre-rendered HTML from component usage, so escaping would
     * double-escape them (e.g., <strong> becomes &lt;strong&gt;).
     *
     * @param \Sugar\Ast\Node $node Node to process
     * @param array<string> $slotVars List of slot variable names
     */
    private function processNodeForSlotWrapping(Node $node, array $slotVars): void
    {
        if ($node instanceof OutputNode) {
            // Check if this output references a slot variable
            // Match patterns like: $slot, $header, ($slot), $footer ?? '', isset($slot) ? $slot : ''
            foreach ($slotVars as $varName) {
                if ($this->expressionReferencesVariable($node->expression, $varName)) {
                    // Disable escaping for this slot output (it's already safe HTML)
                    $node->escape = false;
                    break;
                }
            }
        }

        // Recursively process children for all node types that have them
        if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof DocumentNode) {
            foreach ($node->children as $child) {
                $this->processNodeForSlotWrapping($child, $slotVars);
            }
        }

        // Process element attributes (for OutputNode in attribute values)
        if ($node instanceof ElementNode) {
            foreach ($node->attributes as $attr) {
                if ($attr->value instanceof OutputNode) {
                    $this->processNodeForSlotWrapping($attr->value, $slotVars);
                }
            }
        }
    }

    /**
     * Check if a PHP expression references a specific variable
     *
     * @param string $expression PHP expression
     * @param string $varName Variable name (without $)
     * @return bool True if expression references the variable
     */
    private function expressionReferencesVariable(string $expression, string $varName): bool
    {
        // Match $varName as a standalone variable or with array/object access
        // Patterns: $slot, $slot['key'], $slot->prop, ($slot), $slot ?? ''
        $pattern = '/\$' . preg_quote($varName, '/') . '(?![a-zA-Z0-9_])/';

        return (bool)preg_match($pattern, $expression);
    }

    /**
     * Convert nodes to PHP string representation for injection
     *
     * @param array<\Sugar\Ast\Node> $nodes Nodes to convert
     * @return string PHP string representation
     */
    private function nodesToPhpString(array $nodes): string
    {
        if ($nodes === []) {
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
