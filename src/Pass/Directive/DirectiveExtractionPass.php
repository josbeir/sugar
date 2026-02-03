<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use RuntimeException;
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
use Sugar\Enum\DirectiveType;
use Sugar\Enum\InheritanceAttribute;
use Sugar\Enum\OutputContext;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Extension\ExtensionRegistry;

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
final readonly class DirectiveExtractionPass
{
    private DirectivePrefixHelper $prefixHelper;

    /**
     * Constructor
     *
     * @param \Sugar\Extension\ExtensionRegistry $registry Directive registry for type checking
     * @param string $directivePrefix Prefix for directive attributes (e.g., 's', 'x', 'v')
     */
    public function __construct(
        private ExtensionRegistry $registry,
        string $directivePrefix = 's',
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($directivePrefix);
    }

    /**
     * Transform AST by extracting directives from elements
     */
    public function transform(DocumentNode $ast): DocumentNode
    {
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
     * - Attribute: Multiple allowed, remain as element attributes
     *
     * @return array{controlFlow: array{name: string, expression: string}|null, content: array{name: string, expression: string}|null, remaining: array<\Sugar\Ast\AttributeNode>}
     */
    private function extractDirective(ElementNode $node): array
    {
        $controlFlowDirective = null;
        $contentDirective = null;
        $remainingAttrs = [];

        foreach ($node->attributes as $attr) {
            if ($this->prefixHelper->isDirective($attr->name)) {
                $name = $this->prefixHelper->stripPrefix($attr->name);

                // Directive expressions must be strings, not OutputNodes
                if ($attr->value instanceof OutputNode) {
                    throw new RuntimeException('Directive attributes cannot contain dynamic output expressions');
                }

                $expression = $attr->value ?? 'true';

                // Get directive type
                $compiler = $this->registry->getDirective($name);
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
                    // Attribute directives are compiled inline and added to remaining attributes
                    DirectiveType::ATTRIBUTE => $this->compileAttributeDirective(
                        $compiler,
                        $name,
                        $expression,
                        $attr->line,
                        $attr->column,
                        $remainingAttrs,
                    ),
                };
            } else {
                $remainingAttrs[] = $attr;
            }
        }

        return [
            'controlFlow' => $controlFlowDirective,
            'content' => $contentDirective,
            'remaining' => $remainingAttrs,
        ];
    }

    /**
     * Transform ElementNode with directive attribute into DirectiveNode or ElementNode
     *
     * If the element only has attribute directives (s:class, s:spread), it remains an ElementNode
     * with those directives in its attributes array. The DirectiveCompilationPass will handle them.
     */
    private function elementToDirective(ElementNode $node): DirectiveNode|ElementNode
    {
        $directives = $this->extractDirective($node);

        // Transform children recursively
        $transformedChildren = $this->transformChildren($node->children);

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
                // Directive expressions must be strings, not OutputNodes
                if ($attr->value instanceof OutputNode) {
                    throw new RuntimeException('Directive attributes cannot contain dynamic output expressions');
                }

                $expression = $attr->value ?? 'true';
                // Get directive type
                $compiler = $this->registry->getDirective($name);
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
                    DirectiveType::ATTRIBUTE => throw new RuntimeException(
                        sprintf('<s-template> cannot have attribute directives like s:%s. ', $name) .
                        'Only control flow and content directives are allowed.',
                    ),
                };
            } elseif (!InheritanceAttribute::isInheritanceAttribute($attr->name)) {
                // Allow template inheritance attributes on fragments
                // These are processed by TemplateInheritancePass before DirectiveExtractionPass
                throw new RuntimeException(
                    sprintf('<s-template> cannot have regular HTML attributes. Found: %s. ', $attr->name) .
                    'Only s: directives and template inheritance attributes ' .
                    '(s:block, s:include, etc.) are allowed.',
                );
            }
        }

        // Must have at least one directive or inheritance attribute
        if ($controlFlowDirective === null && $contentDirective === null) {
            // Check if there's at least one inheritance attribute
            $hasInheritanceAttr = false;
            foreach ($node->attributes as $attr) {
                if (InheritanceAttribute::isInheritanceAttribute($attr->name)) {
                    $hasInheritanceAttr = true;
                    break;
                }
            }

            if (!$hasInheritanceAttr) {
                throw new RuntimeException('<s-template> must have at least one directive or inheritance attribute');
            }

            // Fragment with only inheritance attributes - return children directly
            // TemplateInheritancePass will handle these attributes
            return $node;
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
     * @param \Sugar\Extension\DirectiveCompilerInterface $compiler Directive compiler
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
        $compiledNodes = $compiler->compile($directiveNode);

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
