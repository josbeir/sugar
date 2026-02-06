<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Directive\Interface\ElementExtractionInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\PassInterface;

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
final class DirectiveExtractionPass implements PassInterface
{
    private DirectivePrefixHelper $prefixHelper;

    private CompilationContext $context;

    /**
     * Constructor
     *
     * @param \Sugar\Extension\DirectiveRegistry $registry Directive registry for type checking
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly DirectiveRegistry $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
    }

    /**
     * Execute the pass: extract directive attributes into DirectiveNodes
     *
     * @param \Sugar\Ast\DocumentNode $ast Document to transform
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @return \Sugar\Ast\DocumentNode New document with directives extracted
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        $this->context = $context;
        $children = $this->transformChildren($ast->children);

        return new DocumentNode($children);
    }

    /**
     * Transform a list of child nodes, handling directive pairing
     *
     * @param array<\Sugar\Ast\Node> $nodes
     * @return array<\Sugar\Ast\Node>
     */
    private function transformChildren(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if ($node instanceof ElementNode && $this->hasDirectiveAttribute($node)) {
                // Convert element with directive into DirectiveNode
                // Don't pair at extraction time - let DirectivePairingPass handle that
                $result[] = $this->elementToDirective($node);
            } elseif ($node instanceof FragmentNode && $this->hasFragmentDirectiveAttribute($node)) {
                // Convert fragment with directive into DirectiveNode
                $result[] = $this->fragmentToDirective($node);
            } else {
                // Transform children recursively
                $result[] = $this->transformNode($node);
            }
        }

        return $result;
    }

    /**
     * Transform a node and its children recursively
     */
    private function transformNode(Node $node): Node
    {
        // Handle elements with children
        if ($node instanceof ElementNode) {
            $newChildren = $this->transformChildren($node->children);

            return NodeCloner::withChildren($node, $newChildren);
        }

        // Handle fragments with children
        if ($node instanceof FragmentNode) {
            $newChildren = $this->transformChildren($node->children);

            return NodeCloner::fragmentWithChildren($node, $newChildren);
        }

        // All other nodes pass through unchanged
        return $node;
    }

    /**
     * Check if an ElementNode has a directive attribute (s:if, s:foreach, etc.)
     */
    private function hasDirectiveAttribute(ElementNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a FragmentNode has a directive attribute
     */
    private function hasFragmentDirectiveAttribute(FragmentNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
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
     * - Custom Extraction: Directives implementing ElementExtractionInterface (multiple allowed, processed in order)
     * - Attribute: Multiple allowed, remain as element attributes
     *
     * @return array{controlFlow: array{name: string, expression: string, attr: \Sugar\Ast\AttributeNode}|null, content: array{name: string, expression: string, attr: \Sugar\Ast\AttributeNode}|null, customExtraction: array<array{name: string, expression: string, attr: \Sugar\Ast\AttributeNode, compiler: \Sugar\Directive\Interface\ElementExtractionInterface}>, remaining: array<\Sugar\Ast\AttributeNode>}
     */
    private function extractDirective(ElementNode $node): array
    {
        $controlFlowDirective = null;
        $contentDirective = null;
        $customExtractionDirectives = [];
        $remainingAttrs = [];
        $controlFlowCount = 0;
        $contentCount = 0;

        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                $name = $this->prefixHelper->stripPrefix($attr->name);

                // Directive expressions must be strings, not OutputNodes
                if ($attr->value instanceof OutputNode) {
                    throw $this->context->createException(
                        SyntaxException::class,
                        'Directive attributes cannot contain dynamic output expressions',
                        $attr->line,
                        $attr->column,
                    );
                }

                $expression = $attr->value ?? 'true';

                // Get directive type
                $compiler = $this->registry->get($name);
                $type = $compiler->getType();

                // Check if directive needs custom extraction
                if ($compiler instanceof ElementExtractionInterface) {
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
                        $attr->line,
                        $attr->column,
                        $remainingAttrs,
                    ),
                };

                // Count directive types for validation
                if ($type === DirectiveType::CONTROL_FLOW) {
                    $controlFlowCount++;
                    if ($controlFlowCount > 1) {
                        throw $this->context->createException(
                            SyntaxException::class,
                            'Only one control flow directive allowed per element. ' .
                            'Nest elements to combine directives. Example: ' .
                            '<div ' . $this->prefixHelper->getPrefix() . ':if="$condition">' .
                            '<div ' . $this->prefixHelper->getPrefix() . ':foreach="$items as $item">...</div>' .
                            '</div>',
                            $attr->line,
                            $attr->column,
                        );
                    }
                }

                if ($type === DirectiveType::CONTENT) {
                    $contentCount++;
                    if ($contentCount > 1) {
                        throw $this->context->createException(
                            SyntaxException::class,
                            'Only one content directive allowed per element. ' .
                            'Use either ' . $this->prefixHelper->getPrefix() . ':text or ' .
                            $this->prefixHelper->getPrefix() . ':html, not both.',
                            $attr->line,
                            $attr->column,
                        );
                    }
                }
            } else {
                $remainingAttrs[] = $attr;
            }
        }

        return [
            'controlFlow' => $controlFlowDirective,
            'content' => $contentDirective,
            'customExtraction' => $customExtractionDirectives,
            'remaining' => $remainingAttrs,
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
        $transformedChildren = $this->transformChildren($node->children);

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
                return new FragmentNode(
                    attributes: [],
                    children: [...$prefixNodes, $currentNode],
                    line: $node->line,
                    column: $node->column,
                );
            }

            return $currentNode;
        }

        // If there are only attribute directives, return ElementNode with them
        if ($directives['controlFlow'] === null && $directives['content'] === null) {
            return NodeCloner::withAttributesAndChildren($node, $directives['remaining'], $transformedChildren);
        }

        // If there's a content directive, wrap it as a DirectiveNode in children
        if ($directives['content'] !== null) {
            $contentDir = $directives['content'];
            $transformedChildren = [
                new DirectiveNode(
                    name: $contentDir['name'],
                    expression: $contentDir['expression'],
                    children: $transformedChildren,
                    line: $node->line,
                    column: $node->column,
                ),
            ];
        }

        // Create element without control flow directive but keep attribute directives
        $wrappedElement = NodeCloner::withAttributesAndChildren($node, $directives['remaining'], $transformedChildren);

        // If there's a control flow directive, wrap everything in it
        if ($directives['controlFlow'] !== null) {
            $controlDir = $directives['controlFlow'];

            return new DirectiveNode(
                name: $controlDir['name'],
                expression: $controlDir['expression'],
                children: [$wrappedElement],
                line: $node->line,
                column: $node->column,
            );
        }

        // Only content directive - return it directly
        // This is guaranteed non-null due to the check above
        assert($directives['content'] !== null);

        return new DirectiveNode(
            name: $directives['content']['name'],
            expression: $directives['content']['expression'],
            children: [$wrappedElement],
            line: $node->line,
            column: $node->column,
        );
    }

    /**
     * Transform FragmentNode with directive attribute into DirectiveNode
     *
     * Fragments can only have directive attributes, not regular HTML attributes.
     * Returns FragmentNode if it only has inheritance attributes (processed later).
     */
    private function fragmentToDirective(FragmentNode $node): DirectiveNode|FragmentNode
    {
        // Extract directives from fragment
        $controlFlowDirective = null;
        $contentDirective = null;

        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                $name = $this->prefixHelper->stripPrefix($attr->name);

                // Skip inheritance attributes - they're processed by TemplateInheritancePass
                if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
                    continue;
                }

                // Directive expressions must be strings, not OutputNodes
                if ($attr->value instanceof OutputNode) {
                    throw $this->context->createException(
                        SyntaxException::class,
                        'Directive attributes cannot contain dynamic output expressions',
                        $attr->line,
                        $attr->column,
                    );
                }

                $expression = $attr->value ?? 'true';
                // Get directive type
                $compiler = $this->registry->get($name);
                $type = $compiler->getType();
                match ($type) {
                    DirectiveType::CONTROL_FLOW => $controlFlowDirective = [
                        'name' => $name,
                        'expression' => $expression,
                    ],
                    DirectiveType::CONTENT => $contentDirective = [
                        'name' => $name,
                        'expression' => $expression,
                    ],
                    DirectiveType::ATTRIBUTE => throw $this->context->createException(
                        SyntaxException::class,
                        sprintf('<s-template> cannot have attribute directives like s:%s. ', $name) .
                            'Only control flow and content directives are allowed.',
                        $attr->line,
                        $attr->column,
                    ),
                };
            } elseif (!$this->prefixHelper->isInheritanceAttribute($attr->name)) {
                // Allow template inheritance attributes on fragments
                // These are processed by TemplateInheritancePass before DirectiveExtractionPass
                throw $this->context->createException(
                    SyntaxException::class,
                    sprintf('<s-template> cannot have regular HTML attributes. Found: %s. ', $attr->name) .
                        'Only s: directives and template inheritance attributes ' .
                        '(s:block, s:include, etc.) are allowed.',
                    $attr->line,
                    $attr->column,
                );
            }
        }

        // Must have at least one directive or inheritance attribute
        if ($controlFlowDirective === null && $contentDirective === null) {
            // Check if there's at least one inheritance attribute
            $hasInheritanceAttr = false;
            foreach ($node->attributes as $attr) {
                if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
                    $hasInheritanceAttr = true;
                    break;
                }
            }

            if (!$hasInheritanceAttr) {
                throw $this->context->createException(
                    SyntaxException::class,
                    '<s-template> must have at least one directive or inheritance attribute',
                    $node->line,
                    $node->column,
                );
            }

            // Fragment with only inheritance attributes - transform children and return
            // TemplateInheritancePass will handle these attributes
            $transformedChildren = $this->transformChildren($node->children);

            return NodeCloner::fragmentWithChildren($node, $transformedChildren);
        }

        // Transform children recursively
        $transformedChildren = $this->transformChildren($node->children);

        // If there's a content directive, wrap it as a DirectiveNode in children
        if ($contentDirective !== null) {
            $contentDir = $contentDirective;
            $transformedChildren = [
                new DirectiveNode(
                    name: $contentDir['name'],
                    expression: $contentDir['expression'],
                    children: $transformedChildren,
                    line: $node->line,
                    column: $node->column,
                ),
            ];
        }

        // If there's a control flow directive, wrap children directly in it
        // Fragments don't need a wrapper element - their children render directly
        if ($controlFlowDirective !== null) {
            $controlDir = $controlFlowDirective;

            return new DirectiveNode(
                name: $controlDir['name'],
                expression: $controlDir['expression'],
                children: $transformedChildren,
                line: $node->line,
                column: $node->column,
            );
        }

        // Only content directive - return it directly
        // This is guaranteed non-null due to the check above
        assert($contentDirective !== null);

        return new DirectiveNode(
            name: $contentDirective['name'],
            expression: $contentDirective['expression'],
            children: $transformedChildren,
            line: $node->line,
            column: $node->column,
        );
    }

    /**
     * Compile attribute directive inline and add to remaining attributes
     *
     * Attribute directives like s:class and s:spread are compiled immediately
     * and added back as regular attributes with OutputNode values.
     *
     * @param \Sugar\Directive\Interface\DirectiveCompilerInterface $compiler Directive compiler
     * @param string $name Directive name
     * @param string $expression Directive expression
     * @param int $line Line number
     * @param int $column Column number
     * @param array<\Sugar\Ast\AttributeNode> &$remainingAttrs Reference to remaining attributes array
     */
    private function compileAttributeDirective(
        DirectiveCompilerInterface $compiler,
        string $name,
        string $expression,
        int $line,
        int $column,
        array &$remainingAttrs,
    ): void {
        // Create a temporary DirectiveNode for compilation
        $directiveNode = new DirectiveNode(
            name: $name,
            expression: $expression,
            children: [],
            line: $line,
            column: $column,
        );

        // Compile the directive - attribute directives return nodes that represent attribute output
        $compiledNodes = $compiler->compile($directiveNode, $this->context);

        // Convert compiled nodes to attribute format
        // Attribute compilers can return two formats:
        // 1. Named attribute: name="value" (e.g., s:class)
        // 2. Spread output: raw output without name (e.g., s:spread)
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
                $remainingAttrs[] = new AttributeNode(
                    name: $attrName,
                    value: new OutputNode(
                        expression: trim(str_replace(['<?=', '?>', '<?php', 'echo'], '', $attrValue)),
                        escape: false, // Already handled by the directive compiler
                        context: OutputContext::HTML_ATTRIBUTE,
                        line: $line,
                        column: $column,
                    ),
                    line: $line,
                    column: $column,
                );
            } else {
                // Spread format - raw output without name
                // Empty name signals to code generator to output directly
                $remainingAttrs[] = new AttributeNode(
                    name: '',
                    value: new OutputNode(
                        expression: trim(str_replace(['<?=', '?>', '<?php', 'echo'], '', $node->code)),
                        escape: false,
                        context: OutputContext::HTML_ATTRIBUTE,
                        line: $line,
                        column: $column,
                    ),
                    line: $line,
                    column: $column,
                );
            }
        }
    }
}
