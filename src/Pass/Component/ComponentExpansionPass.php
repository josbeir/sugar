<?php
declare(strict_types=1);

namespace Sugar\Pass\Component;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Ast\Helper\ExpressionValidator;
use Sugar\Ast\Helper\NodeTraverser;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RuntimeCallNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Pass\Component\Helper\ComponentAttributeCategorizer;
use Sugar\Pass\Component\Helper\ComponentSlots;
use Sugar\Pass\Component\Helper\SlotResolver;
use Sugar\Pass\Trait\ScopeIsolationTrait;
use Sugar\Runtime\RuntimeEnvironment;

/**
 * Expands component invocations into their template content
 *
 * Replaces ComponentNode instances with their actual template content,
 * injecting slots and attributes as variables.
 */
final class ComponentExpansionPass implements AstPassInterface
{
    use ScopeIsolationTrait;

    private readonly DirectivePrefixHelper $prefixHelper;

    private readonly string $slotAttrName;

    private readonly AstPipeline $componentTemplatePipeline;

    private readonly ComponentAttributeCategorizer $attributeCategorizer;

    private readonly SlotResolver $slotResolver;

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
     * @param \Sugar\Compiler\Pipeline\AstPipeline $componentTemplatePipeline Pipeline for component templates
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
        AstPipeline $componentTemplatePipeline,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->slotAttrName = $config->directivePrefix . ':slot';
        $this->componentTemplatePipeline = $componentTemplatePipeline;
        $this->attributeCategorizer = new ComponentAttributeCategorizer($this->registry, $this->prefixHelper);
        $this->slotResolver = new SlotResolver($this->slotAttrName);
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof ComponentNode) {
            return NodeAction::replace($this->expandComponent($node, $context->compilation, true));
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            $component = $this->tryConvertComponentDirective($node, $context->compilation);
            if ($component instanceof RuntimeCallNode) {
                return NodeAction::replace($component);
            }

            if ($component instanceof ComponentNode) {
                return NodeAction::replace($this->expandComponent($component, $context->compilation, true));
            }
        }

        return NodeAction::none();
    }

    /**
     * Expand a single component node
     *
     * @param \Sugar\Ast\ComponentNode $component Component to expand
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for dependency tracking
     * @return array<\Sugar\Ast\Node> Expanded nodes
     */
    private function expandComponent(
        ComponentNode $component,
        ?CompilationContext $context = null,
        bool $slotsExpanded = false,
    ): array {
        // Load component template
        $templateContent = $this->loader->loadComponent($component->name);

        // Track component as dependency
        $context?->tracker?->addComponent($this->loader->getComponentFilePath($component->name));

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
        // Process template inheritance and directives in component template
        $templateAst = $this->componentTemplatePipeline->execute($templateAst, $inheritanceContext);

        // Categorize attributes: control flow, attribute directives, bindings, merge
        $categorized = $this->attributeCategorizer->categorize($component->attributes);

        // Find root element in component template for attribute merging
        $rootElement = NodeTraverser::findRootElement($templateAst);

        // Merge non-binding attributes to root element
        if ($rootElement instanceof ElementNode) {
            $this->mergeAttributesToRoot(
                $rootElement,
                $categorized->merge,
                $categorized->attributeDirectives,
            );
        }

        // Extract slots from component usage BEFORE expanding (so we can detect s:slot attributes)
        $slots = $this->slotResolver->extract($component->children);

        $expandedSlots = $slotsExpanded ? $slots : $this->expandSlotContent($slots, $context);

        // Wrap component template with variable injections (only s-bind: attributes become variables)
        $wrappedTemplate = $this->wrapWithVariables(
            $templateAst,
            $categorized->componentBindings,
            $expandedSlots,
            $context,
        );

        // Recursively expand any nested components in the wrapped template itself
        $expandedContent = $this->expandNodes($wrappedTemplate->children, $context);

        // If component has control flow directives, wrap in FragmentNode
        if ($categorized->controlFlow !== []) {
            return [new FragmentNode(
                attributes: $categorized->controlFlow,
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
        /**
         * @return \Sugar\Ast\Node|array<\Sugar\Ast\Node>
         */
        $visitor = function (Node $node, callable $recurse) use ($context): Node|array {
            /** @var callable(\Sugar\Ast\Node): \Sugar\Ast\Node $recurse */
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
        };

        return NodeTraverser::walk($nodes, $visitor);
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
        $value = $attr->value->isStatic() ? $attr->value->static : null;

        if ($value === null || $value === '') {
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
        $categorized = $this->attributeCategorizer->categorize($attributes);

        $bindingsExpression = '[]';
        $bindContext = $this->prefixHelper->buildName('bind') . ' attribute';
        if ($categorized->componentBindings instanceof AttributeNode) {
            $bindAttribute = $categorized->componentBindings;
            $bindingsValue = $bindAttribute->value;

            if ($bindingsValue->isBoolean()) {
                $message = sprintf(
                    '%s attribute must have a value (e.g., %s="[\'key\' => $value]")',
                    $this->prefixHelper->buildName('bind'),
                    $this->prefixHelper->buildName('bind'),
                );
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

            if ($bindingsValue->isParts()) {
                $message = sprintf(
                    '%s attribute cannot contain mixed output expressions',
                    $this->prefixHelper->buildName('bind'),
                );
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

            if ($bindingsValue->isOutput()) {
                $output = $bindingsValue->output;
                $bindingsExpression = $output instanceof OutputNode ? $output->expression : '[]';
            } else {
                $bindingsExpression = $bindingsValue->static ?? '[]';
            }

            ExpressionValidator::validateArrayExpression(
                $bindingsExpression,
                $bindContext,
                $context,
                $bindAttribute->line,
                $bindAttribute->column,
            );
        }

        $slots = $this->slotResolver->extract($children);
        $slotsExpression = $this->slotResolver->buildSlotsExpression($slots);
        $attributesExpression = $this->buildRuntimeAttributesExpression(array_merge(
            $categorized->merge,
            $categorized->attributeDirectives,
        ));

        return new RuntimeCallNode(
            callableExpression: RuntimeEnvironment::class . '::getRenderer()->renderComponent',
            arguments: [$nameExpression, $bindingsExpression, $slotsExpression, $attributesExpression],
            line: $line,
            column: $column,
        );
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

            if ($attr->value->isOutput()) {
                $output = $attr->value->output;
                $value = $output instanceof OutputNode ? $output->expression : 'null';
            } elseif ($attr->value->isBoolean()) {
                $value = 'null';
            } else {
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

                    $value = implode(' . ', $expressionParts);
                } else {
                    $part = $parts[0] ?? '';
                    $value = $part instanceof OutputNode
                        ? '(' . $part->expression . ')'
                        : var_export($part, true);
                }
            }

            $items[] = $key . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
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
                if ($existingClass->isStatic() && $newClass->isStatic()) {
                    $existingValue = $existingClass->static ?? '';
                    $newValue = $newClass->static ?? '';
                    $existingAttrs['class'] = new AttributeNode(
                        'class',
                        AttributeValue::static(trim($existingValue . ' ' . $newValue)),
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
     * Wrap template with PHP code that injects variables
     *
     * @param \Sugar\Ast\DocumentNode $template Component template AST
     * @param \Sugar\Ast\AttributeNode|null $bindAttribute Optional s:bind attribute node
     * @param \Sugar\Pass\Component\Helper\ComponentSlots $slots Slot content
     * @param \Sugar\Context\CompilationContext|null $context Compilation context for error reporting
     * @return \Sugar\Ast\DocumentNode Wrapped template
     */
    private function wrapWithVariables(
        DocumentNode $template,
        ?AttributeNode $bindAttribute,
        ComponentSlots $slots,
        ?CompilationContext $context = null,
    ): DocumentNode {
        $arrayItems = [];

        $bindContext = $this->prefixHelper->buildName('bind') . ' attribute';

        // Add component bindings using spread operator if provided
        if ($bindAttribute instanceof AttributeNode) {
            $bindingsExpression = $bindAttribute->value;

            // s:bind attribute must have a value
            if ($bindingsExpression->isBoolean()) {
                $message = sprintf(
                    '%s attribute must have a value (e.g., %s="[\'key\' => $value]")',
                    $this->prefixHelper->buildName('bind'),
                    $this->prefixHelper->buildName('bind'),
                );
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

            if ($bindingsExpression->isParts()) {
                $message = sprintf(
                    '%s attribute cannot contain mixed output expressions',
                    $this->prefixHelper->buildName('bind'),
                );
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

            if ($bindingsExpression->isOutput()) {
                $output = $bindingsExpression->output;
                $expression = $output instanceof OutputNode ? $output->expression : '[]';
            } else {
                $expression = $bindingsExpression->static ?? '[]';
            }

            // Validate that expression could be an array at compile time
            ExpressionValidator::validateArrayExpression(
                $expression,
                $bindContext,
                $context,
                $bindAttribute->line,
                $bindAttribute->column,
            );

            $arrayItems[] = '...(' . $expression . ')';
        }

        $arrayItems = array_merge($arrayItems, $this->slotResolver->buildSlotItems($slots));

        $slotVars = $this->slotResolver->buildSlotVars($slots);

        // Automatically disable escaping for slot variable outputs in component template
        SlotResolver::disableEscaping($template, $slotVars);

        // Use trait method for consistent closure wrapping with parent passes
        return $this->wrapInIsolatedScope($template, '[' . implode(', ', $arrayItems) . ']');
    }

    /**
     * Expand nested components inside slot content.
     */
    private function expandSlotContent(ComponentSlots $slots, ?CompilationContext $context): ComponentSlots
    {
        $expandedSlots = [];
        foreach ($slots->namedSlots as $name => $nodes) {
            $expandedSlots[$name] = $this->expandNodes($nodes, $context);
        }

        $expandedDefaultSlot = $this->expandNodes($slots->defaultSlot, $context);

        return new ComponentSlots($expandedSlots, $expandedDefaultSlot);
    }
}
