<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Directive;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Enum\AttributeMergeMode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Core\Directive\Interface\ContentWrappingDirectiveInterface;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Extension\DirectiveRegistryInterface;

/**
 * Extracts directive attributes from elements and creates DirectiveNodes
 *
 * This pass walks the AST looking for elements with s:* attributes
 * (s:if, s:foreach, s:while, etc.) and converts them into DirectiveNode
 * instances. It does NOT compile directives - that's handled by
 * DirectiveCompilationPass.
 *
 * Supports multiple directives on same element based on type:
 * - Control Flow (if, foreach, while) - wraps element (one per element)
 * - Attribute (class, spread) - modifies attributes (multiple allowed)
 * - Content (text, html) - injects content (one per element)
 *
 * Example:
 * ```
 * <div s:if="$user">Content</div>
 * ```
 *
 * Becomes:
 * ```
 * DirectiveNode(name: 'if', expression: '$user', children: [<div>Content</div>])
 * ```
 */
final class DirectiveExtractionPass implements AstPassInterface
{
    private DirectivePrefixHelper $prefixHelper;

    private DirectiveClassifier $directiveClassifier;

    private CompilationContext $context;

    /**
     * Constructor
     *
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry for type checking
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->directiveClassifier = new DirectiveClassifier($this->registry, $this->prefixHelper);
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            $this->context = $context->compilation;
        }

        $this->preTransformChildren($node);

        if ($node instanceof ElementNode && $this->hasDirectiveAttribute($node)) {
            return NodeAction::replace($this->elementToDirective($node));
        }

        if ($node instanceof ComponentNode && $this->hasComponentDirectiveAttribute($node)) {
            return NodeAction::replace($this->componentToDirective($node));
        }

        if ($node instanceof FragmentNode && $this->hasFragmentDirectiveAttribute($node)) {
            return NodeAction::replace($this->fragmentToDirective($node));
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
     * Pre-transform immediate children into directive nodes when needed.
     */
    private function preTransformChildren(Node $node): void
    {
        $children = $this->getChildren($node);
        if ($children === null) {
            return;
        }

        foreach ($children as $index => $child) {
            if ($child instanceof ElementNode && $this->hasDirectiveAttribute($child)) {
                $children[$index] = $this->elementToDirective($child);
                continue;
            }

            if ($child instanceof ComponentNode && $this->hasComponentDirectiveAttribute($child)) {
                $children[$index] = $this->componentToDirective($child);
                continue;
            }

            if ($child instanceof FragmentNode && $this->hasFragmentDirectiveAttribute($child)) {
                $children[$index] = $this->fragmentToDirective($child);
            }
        }

        $this->setChildren($node, $children);
    }

    /**
     * @return array<\Sugar\Core\Ast\Node>|null
     */
    private function getChildren(Node $node): ?array
    {
        if (
            $node instanceof DocumentNode ||
            $node instanceof ElementNode ||
            $node instanceof FragmentNode ||
            $node instanceof ComponentNode ||
            $node instanceof DirectiveNode
        ) {
            return $node->children;
        }

        return null;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    private function setChildren(Node $node, array $children): void
    {
        if (
            $node instanceof DocumentNode ||
            $node instanceof ElementNode ||
            $node instanceof FragmentNode ||
            $node instanceof ComponentNode ||
            $node instanceof DirectiveNode
        ) {
            $node->children = $children;
        }
    }

    /**
     * Check if an ElementNode has a directive attribute (s:if, s:foreach, etc.)
     * Pass-through directives (like s:slot) are NOT counted as real directives
     */
    private function hasDirectiveAttribute(ElementNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if ($this->directiveClassifier->isNonPassThroughDirectiveAttribute($attr->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a FragmentNode has a directive attribute
     * Pass-through directives (like s:slot) are NOT counted as real directives
     */
    private function hasFragmentDirectiveAttribute(FragmentNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if ($this->directiveClassifier->isNonPassThroughDirectiveAttribute($attr->name, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a ComponentNode has a directive attribute
     * Pass-through directives (like s:slot) are NOT counted as real directives
     */
    private function hasComponentDirectiveAttribute(ComponentNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if ($this->directiveClassifier->isNonPassThroughDirectiveAttribute($attr->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract directives from element attributes
     *
     * Separates directives by type:
     * - Control Flow: Only one allowed, wraps the element
     * - Content: Only one allowed, injects into children
     * - Custom Extraction: Directives implementing ElementAwareDirectiveInterface (multiple allowed, processed in order)
     * - Attribute: Multiple allowed, remain as element attributes
     *
     * @return array{controlFlow: array{name: string, expression: string, attr: \Sugar\Core\Ast\AttributeNode}|null, content: array{name: string, expression: string, attr: \Sugar\Core\Ast\AttributeNode}|null, customExtraction: array<array{name: string, expression: string, attr: \Sugar\Core\Ast\AttributeNode, compiler: \Sugar\Core\Directive\Interface\ElementAwareDirectiveInterface}>, remaining: array<\Sugar\Core\Ast\AttributeNode>, wrapContentElement: bool}
     */
    private function extractDirective(ElementNode|ComponentNode $node): array
    {
        $controlFlowDirective = null;
        $contentDirective = null;
        $customExtractionDirectives = [];
        $remainingAttrs = [];
        $controlFlowCount = 0;
        $contentCount = 0;
          $wrapContentElement = true;
          $wrapContentElementDirective = null;
          $wrapContentElementAttr = null;

        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                // Inheritance attributes are handled by InheritanceCompilationPass - skip them
                if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
                    $remainingAttrs[] = $attr;
                    continue;
                }

                $name = $this->prefixHelper->stripPrefix($attr->name);

                // Directive expressions must be static strings
                if (!$attr->value->isStatic() && !$attr->value->isBoolean()) {
                    throw $this->context->createSyntaxExceptionForAttribute(
                        'Directive attributes cannot contain dynamic output expressions',
                        $attr,
                    );
                }

                $expression = $attr->value->isBoolean() ? 'true' : ($attr->value->static ?? '');

                $this->directiveClassifier->validateDirectiveAttribute($attr, $this->context);

                // Get directive type
                $compiler = $this->registry->get($name);

                if ($compiler instanceof ContentWrappingDirectiveInterface) {
                    $wrapContentElement = $compiler->shouldWrapContentElement();
                    $wrapContentElementDirective = $name;
                    $wrapContentElementAttr = $attr;
                    continue;
                }

                $type = $compiler->getType();

                // Pass-through directives are handled by other passes - keep them as-is
                if ($type === DirectiveType::PASS_THROUGH) {
                    $remainingAttrs[] = $attr;
                    continue;
                }

                // Count directive types for validation (including custom-extraction directives)
                if ($type === DirectiveType::CONTROL_FLOW) {
                    $controlFlowCount++;
                    if ($controlFlowCount > 1) {
                        throw $this->context->createSyntaxExceptionForAttribute(
                            'Only one control flow directive allowed per element. ' .
                            'Nest elements to combine directives. Example: ' .
                            '<div ' . $this->prefixHelper->getPrefix() . ':if="$condition">' .
                            '<div ' . $this->prefixHelper->getPrefix() . ':foreach="$items as $item">...</div>' .
                            '</div>',
                            $attr,
                        );
                    }
                }

                if ($type === DirectiveType::CONTENT) {
                    $contentCount++;
                    if ($contentCount > 1) {
                        throw $this->context->createSyntaxExceptionForAttribute(
                            'Only one content directive allowed per element. ' .
                            'Use either ' . $this->prefixHelper->getPrefix() . ':text or ' .
                            $this->prefixHelper->getPrefix() . ':html, not both.',
                            $attr,
                        );
                    }
                }

                // Check if directive needs custom extraction
                if ($compiler instanceof ElementAwareDirectiveInterface) {
                    $customExtractionDirectives[] = [
                        'name' => $name,
                        'expression' => $expression,
                        'attr' => $attr,
                        'compiler' => $compiler,
                    ];
                    continue;
                }

                match ($type) {
                    DirectiveType::CONTROL_FLOW => $controlFlowDirective = [
                        'name' => $name,
                        'expression' => $expression,
                        'attr' => $attr,
                    ],
                    DirectiveType::CONTENT => $contentDirective = [
                        'name' => $name,
                        'expression' => $expression,
                        'attr' => $attr,
                    ],
                    DirectiveType::ATTRIBUTE => $this->compileAttributeDirective(
                        $compiler,
                        $name,
                        $expression,
                        $attr,
                        $remainingAttrs,
                    ),
                };
            } else {
                $remainingAttrs[] = $attr;
            }
        }

        if ($wrapContentElementDirective !== null && $contentDirective === null) {
            if ($wrapContentElementAttr instanceof AttributeNode) {
                throw $this->context->createSyntaxExceptionForAttribute(
                    sprintf(
                        'The s:%s directive requires a content directive like %s:text or %s:html on the same element.',
                        $wrapContentElementDirective,
                        $this->prefixHelper->getPrefix(),
                        $this->prefixHelper->getPrefix(),
                    ),
                    $wrapContentElementAttr,
                    $wrapContentElementAttr->line,
                    $wrapContentElementAttr->column,
                );
            }

            throw $this->context->createSyntaxExceptionForNode(
                sprintf(
                    'The s:%s directive requires a content directive like %s:text or %s:html on the same element.',
                    $wrapContentElementDirective,
                    $this->prefixHelper->getPrefix(),
                    $this->prefixHelper->getPrefix(),
                ),
                $node,
            );
        }

        return [
            'controlFlow' => $controlFlowDirective,
            'content' => $contentDirective,
            'customExtraction' => $customExtractionDirectives,
            'remaining' => $remainingAttrs,
            'wrapContentElement' => $wrapContentElement,
        ];
    }

    /**
     * Transform ElementNode with directive attribute into DirectiveNode or ElementNode
     *
     * If the element only has attribute directives (s:class, s:spread), it remains an ElementNode
     * with those directives in its attributes array. The DirectiveCompilationPass will handle them.
     */
    private function elementToDirective(ElementNode $node): DirectiveNode|ElementNode|FragmentNode
    {
        $directives = $this->extractDirective($node);

        // Transform children recursively
        $transformedChildren = $node->children;

        // If directive needs custom extraction, chain them all together
        if ($directives['customExtraction'] !== []) {
            // Start with current element and remaining attrs
            $currentNode = NodeCloner::withAttributesAndChildren($node, $directives['remaining'], $transformedChildren);
            $prefixNodes = []; // Collect nodes that should be siblings before the element

            // Apply each custom extraction directive in order
            foreach ($directives['customExtraction'] as $customDir) {
                $result = $customDir['compiler']->extractFromElement(
                    $currentNode,
                    $customDir['expression'],
                    $currentNode->children,
                    $currentNode->attributes,
                );

                // Handle different result types
                if ($result instanceof FragmentNode) {
                    // FragmentNode may contain prefix nodes (like s:tag validation) + the element
                    // Extract the element for further processing, keep prefix nodes
                    $newElement = null;
                    foreach ($result->children as $child) {
                        if ($child instanceof ElementNode) {
                            $newElement = $child;
                        } else {
                            // Non-element children are prefix nodes
                            $prefixNodes[] = $child;
                        }
                    }

                    if ($newElement instanceof ElementNode) {
                        $currentNode = $newElement;
                    } else {
                        // No element found in fragment - use the fragment itself
                        $currentNode = $result;
                        break;
                    }
                } elseif ($result instanceof ElementNode) {
                    // Simple case - element transformed to element
                    $currentNode = $result;
                } else {
                    // Result is DirectiveNode or other - can't extract further
                    $currentNode = $result;
                    break;
                }
            }

            // Wrap in fragment if we have prefix nodes
            if ($prefixNodes !== []) {
                $fragment = new FragmentNode(
                    attributes: [],
                    children: [...$prefixNodes, $currentNode],
                    line: $node->line,
                    column: $node->column,
                );

                $fragment->inheritTemplatePathFrom($node);

                return $fragment;
            }

            return $currentNode;
        }

        // If there are only attribute directives, return ElementNode with them
        if ($directives['controlFlow'] === null && $directives['content'] === null) {
            return NodeCloner::withAttributesAndChildren($node, $directives['remaining'], $transformedChildren);
        }

        // If content directive requests no wrapper, return it without the element
        if ($directives['content'] !== null && $directives['wrapContentElement'] === false) {
            if ($directives['remaining'] !== []) {
                throw $this->context->createSyntaxExceptionForNode(
                    'Content directives without a wrapper cannot include other attributes.',
                    $node,
                );
            }

            $contentDir = $directives['content'];
            $contentNode = new DirectiveNode(
                name: $contentDir['name'],
                expression: $contentDir['expression'],
                children: [],
                line: $node->line,
                column: $node->column,
            );
            $contentNode->inheritTemplatePathFrom($node);

            if ($directives['controlFlow'] !== null) {
                $controlDir = $directives['controlFlow'];
                $controlNode = new DirectiveNode(
                    name: $controlDir['name'],
                    expression: $controlDir['expression'],
                    children: [$contentNode],
                    line: $node->line,
                    column: $node->column,
                );

                $controlNode->inheritTemplatePathFrom($node);

                return $controlNode;
            }

            return $contentNode;
        }

        // If there's a content directive, wrap it as a DirectiveNode in children
        if ($directives['content'] !== null) {
            $contentDir = $directives['content'];
            $contentWrapper = new DirectiveNode(
                name: $contentDir['name'],
                expression: $contentDir['expression'],
                children: $transformedChildren,
                line: $node->line,
                column: $node->column,
            );
            $contentWrapper->inheritTemplatePathFrom($node);
            $transformedChildren = [$contentWrapper];
        }

        // Create element without control flow directive but keep attribute directives
        $wrappedElement = NodeCloner::withAttributesAndChildren($node, $directives['remaining'], $transformedChildren);

        // If there's a control flow directive, wrap everything in it
        if ($directives['controlFlow'] !== null) {
            $controlDir = $directives['controlFlow'];
            $controlNode = new DirectiveNode(
                name: $controlDir['name'],
                expression: $controlDir['expression'],
                children: [$wrappedElement],
                line: $node->line,
                column: $node->column,
            );

            $controlNode->inheritTemplatePathFrom($node);

            return $controlNode;
        }

        // Only content directive - return it directly
        // This is guaranteed non-null due to the check above
        assert($directives['content'] !== null);

        $contentNode = new DirectiveNode(
            name: $directives['content']['name'],
            expression: $directives['content']['expression'],
            children: [$wrappedElement],
            line: $node->line,
            column: $node->column,
        );

        $contentNode->inheritTemplatePathFrom($node);

        return $contentNode;
    }

    /**
     * Transform ComponentNode with directive attribute into DirectiveNode or ComponentNode
     *
     * Similar to elementToDirective but for components. ComponentNodes support
     * control flow and attribute directives but not content directives.
     */
    private function componentToDirective(ComponentNode $node): DirectiveNode|ComponentNode
    {
        $directives = $this->extractDirective($node);

        // Transform children recursively
        $transformedChildren = $node->children;

        // Components don't support custom extraction or content directives
        // If there are only attribute directives, return ComponentNode with them
        if ($directives['controlFlow'] === null) {
            $node->attributes = $directives['remaining'];
            $node->children = $transformedChildren;

            return $node;
        }

        // Create component without control flow directive but keep attribute directives
        $wrappedComponent = clone $node;
        $wrappedComponent->attributes = $directives['remaining'];
        $wrappedComponent->children = $transformedChildren;

        // If there's a control flow directive, wrap everything in it
        $controlDir = $directives['controlFlow'];

        $controlNode = new DirectiveNode(
            name: $controlDir['name'],
            expression: $controlDir['expression'],
            children: [$wrappedComponent],
            line: $node->line,
            column: $node->column,
        );

        $controlNode->inheritTemplatePathFrom($node);

        return $controlNode;
    }

    /**
     * Transform FragmentNode with directive attribute into DirectiveNode.
     *
     * Fragments can only have directive attributes, not regular HTML attributes.
     *
     * When the fragment also carries inheritance attributes (s:block, s:append, etc.),
     * the directive is wrapped in a new FragmentNode that preserves those attributes
     * so InheritanceCompilationPass can process them later.
     *
     * @return \Sugar\Core\Ast\Node DirectiveNode or FragmentNode wrapping a DirectiveNode
     */
    private function fragmentToDirective(FragmentNode $node): Node
    {
        // Collect inheritance attributes to preserve them
        $inheritanceAttrs = [];
        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
                $inheritanceAttrs[] = $attr;
            }
        }

        // Extract directives from fragment
        $controlFlowDirective = null;
        $contentDirective = null;

        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                $name = $this->prefixHelper->stripPrefix($attr->name);

                // Skip inheritance attributes - they're processed by InheritanceCompilationPass
                if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
                    continue;
                }

                // Directive expressions must be static strings
                if (!$attr->value->isStatic() && !$attr->value->isBoolean()) {
                    throw $this->context->createSyntaxExceptionForAttribute(
                        'Directive attributes cannot contain dynamic output expressions',
                        $attr,
                    );
                }

                $expression = $attr->value->isBoolean() ? 'true' : ($attr->value->static ?? '');
                $this->directiveClassifier->validateDirectiveAttribute($attr, $this->context, false);

                // Get directive type
                $compiler = $this->registry->get($name);

                if ($compiler instanceof ContentWrappingDirectiveInterface) {
                    throw $this->context->createSyntaxExceptionForAttribute(
                        sprintf(
                            'The s:%s directive can only be used on elements with %s:text or %s:html.',
                            $name,
                            $this->prefixHelper->getPrefix(),
                            $this->prefixHelper->getPrefix(),
                        ),
                        $attr,
                    );
                }

                $type = $compiler->getType();

                // Pass-through directives are handled by other passes - skip them
                if ($type === DirectiveType::PASS_THROUGH) {
                    continue;
                }

                match ($type) {
                    DirectiveType::CONTROL_FLOW => $controlFlowDirective = [
                        'name' => $name,
                        'expression' => $expression,
                    ],
                    DirectiveType::CONTENT => $contentDirective = [
                        'name' => $name,
                        'expression' => $expression,
                    ],
                    DirectiveType::ATTRIBUTE => throw $this->context->createSyntaxExceptionForAttribute(
                        sprintf('<s-template> cannot have attribute directives like s:%s. ', $name) .
                            'Only control flow and content directives are allowed.',
                        $attr,
                    ),
                };
            } elseif (!$this->prefixHelper->isInheritanceAttribute($attr->name)) {
                // Allow template inheritance attributes on fragments
                // These are processed by InheritanceCompilationPass after DirectiveExtractionPass
                throw $this->context->createSyntaxExceptionForAttribute(
                    sprintf('<s-template> cannot have regular HTML attributes. Found: %s. ', $attr->name) .
                        'Only s: directives and template inheritance attributes ' .
                        '(s:block, s:include, etc.) are allowed.',
                    $attr,
                );
            }
        }

        // Transform children recursively
        $transformedChildren = $node->children;

        // If there's a content directive, wrap it as a DirectiveNode in children
        if ($contentDirective !== null) {
            $contentDir = $contentDirective;
            $contentWrapper = new DirectiveNode(
                name: $contentDir['name'],
                expression: $contentDir['expression'],
                children: $transformedChildren,
                line: $node->line,
                column: $node->column,
            );
            $contentWrapper->inheritTemplatePathFrom($node);
            $transformedChildren = [$contentWrapper];
        }

        // If there's a control flow directive, wrap children directly in it
        // Fragments don't need a wrapper element - their children render directly
        if ($controlFlowDirective !== null) {
            $controlDir = $controlFlowDirective;
            $controlNode = new DirectiveNode(
                name: $controlDir['name'],
                expression: $controlDir['expression'],
                children: $transformedChildren,
                line: $node->line,
                column: $node->column,
            );

            $controlNode->inheritTemplatePathFrom($node);

            return $this->wrapDirectiveWithInheritanceAttributes($controlNode, $inheritanceAttrs, $node);
        }

        // Only content directive - return it directly
        // This is guaranteed non-null due to the check above
        assert($contentDirective !== null);

        $contentNode = new DirectiveNode(
            name: $contentDirective['name'],
            expression: $contentDirective['expression'],
            children: $transformedChildren,
            line: $node->line,
            column: $node->column,
        );

        $contentNode->inheritTemplatePathFrom($node);

        return $this->wrapDirectiveWithInheritanceAttributes($contentNode, $inheritanceAttrs, $node);
    }

    /**
     * Wrap a directive in a FragmentNode when inheritance attributes need preserving.
     *
     * When a fragment carries both directives and inheritance attributes (e.g. s:block
     * with s:foreach), the directive is wrapped in a FragmentNode so that
     * InheritanceCompilationPass can still find and process the inheritance attributes.
     *
     * @param \Sugar\Core\Ast\Node $directiveNode The compiled directive node
     * @param array<\Sugar\Core\Ast\AttributeNode> $inheritanceAttrs Preserved inheritance attributes
     * @param \Sugar\Core\Ast\FragmentNode $originalNode Original fragment for metadata
     * @return \Sugar\Core\Ast\Node DirectiveNode or wrapped FragmentNode
     */
    private function wrapDirectiveWithInheritanceAttributes(
        Node $directiveNode,
        array $inheritanceAttrs,
        FragmentNode $originalNode,
    ): Node {
        if ($inheritanceAttrs === []) {
            return $directiveNode;
        }

        $wrapper = new FragmentNode(
            attributes: $inheritanceAttrs,
            children: [$directiveNode],
            line: $originalNode->line,
            column: $originalNode->column,
        );
        $wrapper->inheritTemplatePathFrom($originalNode);

        return $wrapper;
    }

    /**
     * Compile attribute directive inline and add to remaining attributes
     *
     * Attribute directives like s:class and s:spread are compiled immediately
     * and added back as regular attributes with OutputNode values.
     *
     * @param \Sugar\Core\Directive\Interface\DirectiveInterface $compiler Directive compiler
     * @param string $name Directive name
     * @param string $expression Directive expression
     * @param \Sugar\Core\Ast\AttributeNode $attr Attribute node for origin metadata
     * @param array<\Sugar\Core\Ast\AttributeNode> &$remainingAttrs Reference to remaining attributes array
     */
    private function compileAttributeDirective(
        DirectiveInterface $compiler,
        string $name,
        string $expression,
        AttributeNode $attr,
        array &$remainingAttrs,
    ): void {
        // Create a temporary DirectiveNode for compilation
        $directiveNode = new DirectiveNode(
            name: $name,
            expression: $expression,
            children: [],
            line: $attr->line,
            column: $attr->column,
        );
        $directiveNode->inheritTemplatePathFrom($attr);

        // Compile the directive - attribute directives return nodes that represent attribute output
        $compiledNodes = $compiler->compile($directiveNode, $this->context);

        // Convert compiled nodes to attribute format
        // Attribute compilers can return two formats:
        // 1. Named attribute: name="value" (e.g., s:class)
        // 2. Spread output: raw output without name (e.g., s:spread)
        $mergePolicy = $compiler instanceof AttributeMergePolicyDirectiveInterface ? $compiler : null;

        foreach ($compiledNodes as $node) {
            if (!($node instanceof RawPhpNode)) {
                continue;
            }

            // Try to parse as named attribute format: name="value"
            $pattern = '/^([a-zA-Z][a-zA-Z0-9:_.-]*)="(.+)"$/s';
            if (preg_match($pattern, $node->code, $matches)) {
                // Named attribute format - extract name and value
                $attrName = $matches[1];
                $attrValue = $matches[2];
                $outputNode = new OutputNode(
                    expression: AttributeHelper::normalizeCompiledPhpExpression($attrValue),
                    escape: false, // Already handled by the directive compiler
                    context: OutputContext::HTML_ATTRIBUTE,
                    line: $attr->line,
                    column: $attr->column,
                );
                $outputNode->inheritTemplatePathFrom($attr);

                $newAttr = new AttributeNode(
                    name: $attrName,
                    value: AttributeValue::output($outputNode),
                    line: $attr->line,
                    column: $attr->column,
                );
                $newAttr->inheritTemplatePathFrom($attr);

                if (
                    $mergePolicy instanceof AttributeMergePolicyDirectiveInterface &&
                    $mergePolicy->getAttributeMergeMode() === AttributeMergeMode::MERGE_NAMED &&
                    $mergePolicy->getMergeTargetAttributeName() === $attrName
                ) {
                    $existingIndex = AttributeHelper::findAttributeIndex($remainingAttrs, $attrName);

                    if ($existingIndex !== null) {
                        $existingAttr = $remainingAttrs[$existingIndex];
                        $mergedExpression = $mergePolicy->mergeNamedAttributeExpression(
                            AttributeHelper::attributeValueToPhpExpression($existingAttr->value),
                            AttributeHelper::attributeValueToPhpExpression($newAttr->value),
                        );

                        $mergedOutput = new OutputNode(
                            expression: $mergedExpression,
                            escape: false,
                            context: OutputContext::HTML_ATTRIBUTE,
                            line: $attr->line,
                            column: $attr->column,
                        );
                        $mergedOutput->inheritTemplatePathFrom($attr);

                        $remainingAttrs[$existingIndex] = new AttributeNode(
                            name: $attrName,
                            value: AttributeValue::output($mergedOutput),
                            line: $existingAttr->line,
                            column: $existingAttr->column,
                        );
                        $remainingAttrs[$existingIndex]->inheritTemplatePathFrom($existingAttr);

                        continue;
                    }
                }

                $remainingAttrs[] = $newAttr;
            } else {
                // Spread format - raw output without name
                // Empty name signals to code generator to output directly
                $outputExpression = AttributeHelper::normalizeCompiledPhpExpression($node->code);

                if (
                    $mergePolicy instanceof AttributeMergePolicyDirectiveInterface &&
                    $mergePolicy->getAttributeMergeMode() === AttributeMergeMode::EXCLUDE_NAMED
                ) {
                    $outputExpression = $mergePolicy->buildExcludedAttributesExpression(
                        $expression,
                        AttributeHelper::collectNamedAttributeNames($remainingAttrs),
                    );
                }

                $outputNode = new OutputNode(
                    expression: $outputExpression,
                    escape: false,
                    context: OutputContext::HTML_ATTRIBUTE,
                    line: $attr->line,
                    column: $attr->column,
                );
                $outputNode->inheritTemplatePathFrom($attr);

                $newAttr = new AttributeNode(
                    name: '',
                    value: AttributeValue::output($outputNode),
                    line: $attr->line,
                    column: $attr->column,
                );
                $newAttr->inheritTemplatePathFrom($attr);
                $remainingAttrs[] = $newAttr;
            }
        }
    }
}
