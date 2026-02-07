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
use Sugar\Ast\Helper\ExpressionValidator;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RuntimeCallNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\Helper\SlotOutputHelper;
use Sugar\Pass\Trait\ScopeIsolationTrait;
use Sugar\Runtime\RuntimeEnvironment;

/**
 * Expands component invocations into their template content
 *
 * Replaces ComponentNode instances with their actual template content,
 * injecting slots and attributes as variables.
 */
final class ComponentExpansionPass implements PassInterface
{
    use ScopeIsolationTrait;

    private readonly DirectivePrefixHelper $prefixHelper;

    private readonly string $slotAttrName;

    private readonly TemplateInheritancePass $inheritancePass;

    private readonly DirectiveExtractionPass $directiveExtractionPass;

    private readonly DirectivePairingPass $directivePairingPass;

    private readonly DirectiveCompilationPass $directiveCompilationPass;

    /**
     * @var array<string, \Sugar\Ast\DocumentNode> Cache of parsed component ASTs
     */
    private array $componentAstCache = [];

    /**
     * Constructor
     *
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader for loading components
     * @param \Sugar\Parser\Parser $parser Parser for parsing component templates
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Extension registry for directive type checking
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->slotAttrName = $config->directivePrefix . ':slot';
        $this->inheritancePass = new TemplateInheritancePass($loader, $config);
        $this->directiveExtractionPass = new DirectiveExtractionPass($this->registry, $config);
        $this->directivePairingPass = new DirectivePairingPass($this->registry);
        $this->directiveCompilationPass = new DirectiveCompilationPass($this->registry);
    }

    /**
     * Execute the pass: expand all component invocations
     *
     * @param \Sugar\Ast\DocumentNode $ast Document to process
     * @return \Sugar\Ast\DocumentNode Processed document with expanded components
     * @throws \Sugar\Exception\ComponentNotFoundException If component template not found
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        $expandedChildren = NodeTraverser::walk(
            $ast->children,
            function (Node $node, callable $recurse) use ($context) {
                if ($node instanceof ComponentNode) {
                    // Expand component - return array of nodes
                    return $this->expandComponent($node, $context);
                }

                if ($node instanceof ElementNode || $node instanceof FragmentNode) {
                    $component = $this->tryConvertComponentDirective($node, $context);
                    if ($component instanceof RuntimeCallNode) {
                        return $component;
                    }

                    if ($component instanceof ComponentNode) {
                        return $this->expandComponent($component, $context);
                    }
                }

                // Recurse into children for other nodes
                return $recurse($node);
            },
        );

        return new DocumentNode($expandedChildren);
    }

    /**
     * Expand a single component node
     *
     * @param \Sugar\Ast\ComponentNode $component Component to expand
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for dependency tracking
     * @return array<\Sugar\Ast\Node> Expanded nodes
     */
    private function expandComponent(ComponentNode $component, ?CompilationContext $context = null): array
    {
        // Load component template
        $templateContent = $this->loader->loadComponent($component->name);

        // Track component as dependency
        $context?->tracker?->addComponent($component->name);

        // Cache parsed component ASTs to avoid re-parsing same components
        if (!isset($this->componentAstCache[$component->name])) {
            $this->componentAstCache[$component->name] = $this->parser->parse($templateContent);
        }

        $templateAst = $this->componentAstCache[$component->name];

        // Process template inheritance (s:extends, s:include) in component template
        // Use resolved component path for proper relative path resolution
        $componentPath = $this->loader->getComponentPath($component->name);
        $inheritanceContext = new CompilationContext(
            $componentPath,
            $templateContent,
            $context->debug ?? false,
            $context?->tracker,
        );
        $templateAst = $this->inheritancePass->execute($templateAst, $inheritanceContext);

        // Process directives in component template (s:if, s:class, etc.)
        $templateAst = $this->directiveExtractionPass->execute($templateAst, $inheritanceContext);
        $templateAst = $this->directivePairingPass->execute($templateAst, $inheritanceContext);
        $templateAst = $this->directiveCompilationPass->execute($templateAst, $inheritanceContext);

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
            $expandedSlots[$name] = $this->expandNodes($nodes, $context);
        }

        $expandedDefaultSlot = $this->expandNodes($defaultSlot, $context);

        // Wrap component template with variable injections (only s-bind: attributes become variables)
        $wrappedTemplate = $this->wrapWithVariables(
            $templateAst,
            $categorized['componentBindings'],
            $expandedSlots,
            $expandedDefaultSlot,
            $context,
        );

        // Recursively expand any nested components in the wrapped template itself
        $expandedContent = $this->expandNodes($wrappedTemplate->children, $context);

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
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for dependency tracking
     * @return array<\Sugar\Ast\Node> Processed nodes with expanded components
     */
    private function expandNodes(array $nodes, ?CompilationContext $context = null): array
    {
        return NodeTraverser::walk($nodes, function (Node $node, callable $recurse) use ($context) {
            if ($node instanceof ComponentNode) {
                return $this->expandComponent($node, $context);
            }

            if ($node instanceof ElementNode || $node instanceof FragmentNode) {
                $component = $this->tryConvertComponentDirective($node, $context);
                if ($component instanceof RuntimeCallNode) {
                    return $component;
                }

                if ($component instanceof ComponentNode) {
                    return $this->expandComponent($component, $context);
                }
            }

            return $recurse($node);
        });
    }

    /**
     * Convert s:component directive on an element/fragment into a ComponentNode.
     *
     * @param \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode $node Node to inspect
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for errors
     * @return \Sugar\Ast\ComponentNode|\Sugar\Ast\RuntimeCallNode|null Component node or null if not applicable
     */
    private function tryConvertComponentDirective(
        ElementNode|FragmentNode $node,
        ?CompilationContext $context,
    ): ComponentNode|RuntimeCallNode|null {
        $attrName = $this->prefixHelper->buildName('component');
        $result = AttributeHelper::findAttributeWithIndex($node->attributes, $attrName);

        if ($result === null) {
            return null;
        }

        [$attr, $index] = $result;
        $value = $attr->value;

        if (!is_string($value) || $value === '') {
            $message = 'Component name must be a non-empty string.';
            if ($context instanceof CompilationContext) {
                throw $context->createException(SyntaxException::class, $message, $attr->line, $attr->column);
            }

            throw new SyntaxException($message);
        }

        $attributes = $node->attributes;
        array_splice($attributes, $index, 1);

        $literalName = $this->normalizeComponentName($value);
        if ($literalName !== null) {
            return new ComponentNode(
                name: $literalName,
                attributes: $attributes,
                children: $node->children,
                line: $node->line,
                column: $node->column,
            );
        }

        return $this->createRuntimeComponentCall(
            nameExpression: $value,
            attributes: $attributes,
            children: $node->children,
            line: $node->line,
            column: $node->column,
            context: $context,
        );
    }

    /**
     * Normalize a literal component name or return null for expressions
     */
    private function normalizeComponentName(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([\"\"]).+\1$/s', $trimmed) === 1) {
            $trimmed = substr($trimmed, 1, -1);
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Create a runtime call node for dynamic component rendering
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     * @param array<\Sugar\Ast\Node> $children
     */
    private function createRuntimeComponentCall(
        string $nameExpression,
        array $attributes,
        array $children,
        int $line,
        int $column,
        ?CompilationContext $context,
    ): RuntimeCallNode {
        $categorized = $this->categorizeAttributes($attributes);

        $bindingsExpression = '[]';
        if ($categorized['componentBindings'] instanceof AttributeNode) {
            $bindAttribute = $categorized['componentBindings'];
            $bindingsValue = $bindAttribute->value;

            if ($bindingsValue === null) {
                $message = 's:bind attribute must have a value (e.g., s:bind="[\'key\' => $value]")';
                if ($context instanceof CompilationContext) {
                    throw $context->createException(
                        SyntaxException::class,
                        $message,
                        $bindAttribute->line,
                        $bindAttribute->column,
                    );
                }

                throw new SyntaxException($message);
            }

            $bindingsExpression = $bindingsValue instanceof OutputNode
                ? $bindingsValue->expression
                : $bindingsValue;

            ExpressionValidator::validateArrayExpression(
                $bindingsExpression,
                's:bind attribute',
                $context,
                $bindAttribute->line,
                $bindAttribute->column,
            );
        }

        $slots = $this->extractSlots($children);
        $defaultSlot = $this->extractDefaultSlot($children);

        $slotsExpression = $this->buildSlotsExpression($slots, $defaultSlot);
        $attributesExpression = $this->buildRuntimeAttributesExpression(array_merge(
            $categorized['merge'],
            $categorized['attributeDirectives'],
        ));

        return new RuntimeCallNode(
            callableExpression: RuntimeEnvironment::class . '::getRenderer()->renderComponent',
            arguments: [$nameExpression, $bindingsExpression, $slotsExpression, $attributesExpression],
            line: $line,
            column: $column,
        );
    }

    /**
     * Build runtime slot array expression
     *
     * @param array<string, array<\Sugar\Ast\Node>> $namedSlots
     * @param array<\Sugar\Ast\Node> $defaultSlot
     */
    private function buildSlotsExpression(array $namedSlots, array $defaultSlot): string
    {
        $items = [];

        if ($defaultSlot === []) {
            $items[] = "'slot' => ''";
        } else {
            $items[] = sprintf("'slot' => %s", $this->nodesToPhpString($defaultSlot));
        }

        foreach ($namedSlots as $name => $nodes) {
            $items[] = sprintf("'%s' => %s", $name, $this->nodesToPhpString($nodes));
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Build runtime attributes array expression
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     */
    private function buildRuntimeAttributesExpression(array $attributes): string
    {
        if ($attributes === []) {
            return '[]';
        }

        $items = [];
        foreach ($attributes as $attr) {
            $key = var_export($attr->name, true);

            if ($attr->value instanceof OutputNode) {
                $value = $attr->value->expression;
            } elseif ($attr->value === null) {
                $value = 'null';
            } else {
                $value = var_export($attr->value, true);
            }

            $items[] = $key . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Categorize component attributes into different types
     *
     * @param array<\Sugar\Ast\AttributeNode> $attributes Component attributes
     * @return array{controlFlow: array<\Sugar\Ast\AttributeNode>, attributeDirectives: array<\Sugar\Ast\AttributeNode>, componentBindings: \Sugar\Ast\AttributeNode|null, merge: array<\Sugar\Ast\AttributeNode>}
     */
    private function categorizeAttributes(array $attributes): array
    {
        $controlFlow = [];
        $attributeDirectives = [];
        $componentBindings = null;
        $mergeAttrs = [];

        foreach ($attributes as $attr) {
            $name = $attr->name;

            // Sugar directives: s:if, s:class, s:foreach, s:bind
            if ($this->prefixHelper->isDirective($name)) {
                $directiveName = $this->prefixHelper->stripPrefix($name);

                // s:bind is a pass-through directive for component variable bindings
                if ($directiveName === 'bind') {
                    // Component bindings: s:bind="['title' => $value, 'type' => 'warning']"
                    // Store the full attribute node for error reporting with line/column
                    $componentBindings = $attr;
                } elseif ($this->isControlFlowDirective($name)) {
                    // Control flow: s:if, s:foreach, s:while
                    $controlFlow[] = $attr;
                } else {
                    // Attribute directives: s:class, s:spread
                    $attributeDirectives[] = $attr;
                }
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

        if (!$this->registry->has($name)) {
            return false;
        }

        $compiler = $this->registry->get($name);

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
     * @param \Sugar\Ast\AttributeNode|null $bindAttribute Optional s:bind attribute node
     * @param array<string, array<\Sugar\Ast\Node>> $namedSlots Named slots
     * @param array<\Sugar\Ast\Node> $defaultSlot Default slot content
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for error reporting
     * @return \Sugar\Ast\DocumentNode Wrapped template
     */
    private function wrapWithVariables(
        DocumentNode $template,
        ?AttributeNode $bindAttribute,
        array $namedSlots,
        array $defaultSlot,
        ?CompilationContext $context = null,
    ): DocumentNode {
        $arrayItems = [];

        // Add component bindings using spread operator if provided
        if ($bindAttribute instanceof AttributeNode) {
            $bindingsExpression = $bindAttribute->value;

            // s:bind attribute must have a value
            if ($bindingsExpression === null) {
                $message = 's:bind attribute must have a value (e.g., s:bind="[\'key\' => $value]")';
                if ($context instanceof CompilationContext) {
                    throw $context->createException(
                        SyntaxException::class,
                        $message,
                        $bindAttribute->line,
                        $bindAttribute->column,
                    );
                }

                throw new SyntaxException($message);
            }

            $expression = $bindingsExpression instanceof OutputNode
                ? $bindingsExpression->expression
                : $bindingsExpression;

            // Validate that expression could be an array at compile time
            ExpressionValidator::validateArrayExpression(
                $expression,
                's:bind attribute',
                $context,
                $bindAttribute->line,
                $bindAttribute->column,
            );

            $arrayItems[] = '...(' . $expression . ')';
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
        SlotOutputHelper::disableEscaping($template, $slotVars);

        // Use trait method for consistent closure wrapping with parent passes
        return $this->wrapInIsolatedScope($template, '[' . implode(', ', $arrayItems) . ']');
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
