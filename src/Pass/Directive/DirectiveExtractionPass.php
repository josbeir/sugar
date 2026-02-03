<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Enum\DirectiveType;
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
    /**
     * @param \Sugar\Extension\ExtensionRegistry $registry Directive registry for type checking
     * @param string $directivePrefix Prefix for directive attributes (default: 's')
     */
    public function __construct(
        private ExtensionRegistry $registry,
        private string $directivePrefix = 's',
    ) {
    }

    /**
     * Transform AST by extracting directives from elements
     */
    public function transform(DocumentNode $ast): DocumentNode
    {
        $newChildren = [];

        foreach ($ast->children as $node) {
            if ($node instanceof ElementNode && $this->hasDirectiveAttribute($node)) {
                // Convert element with directive into DirectiveNode
                $newChildren[] = $this->elementToDirective($node);
            } else {
                // Transform children recursively
                $newChildren[] = $this->transformNode($node);
            }
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Transform a node and its children recursively
     */
    private function transformNode(Node $node): Node
    {
        // Handle elements with children
        if ($node instanceof ElementNode) {
            $newChildren = [];
            foreach ($node->children as $child) {
                if ($child instanceof ElementNode && $this->hasDirectiveAttribute($child)) {
                    $newChildren[] = $this->elementToDirective($child);
                } else {
                    $newChildren[] = $this->transformNode($child);
                }
            }

            return new ElementNode(
                tag: $node->tag,
                attributes: $node->attributes,
                children: $newChildren,
                selfClosing: $node->selfClosing,
                line: $node->line,
                column: $node->column,
            );
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
            if (str_starts_with($attr->name, $this->directivePrefix . ':')) {
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
            if (str_starts_with($attr->name, $this->directivePrefix . ':')) {
                $name = substr($attr->name, strlen($this->directivePrefix) + 1);

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
     * Transform ElementNode with directive attribute into DirectiveNode
     */
    private function elementToDirective(ElementNode $node): DirectiveNode
    {
        $directives = $this->extractDirective($node);

        // Must have at least one non-attribute directive
        if ($directives['controlFlow'] === null && $directives['content'] === null) {
            throw new RuntimeException('No control flow or content directive found on element');
        }

        // Transform children recursively
        $transformedChildren = [];
        foreach ($node->children as $child) {
            if ($child instanceof ElementNode && $this->hasDirectiveAttribute($child)) {
                $transformedChildren[] = $this->elementToDirective($child);
            } else {
                $transformedChildren[] = $this->transformNode($child);
            }
        }

        // If there's a content directive, wrap it as a DirectiveNode in children
        if ($directives['content'] !== null) {
            $contentDir = $directives['content'];
            $transformedChildren = [
                new DirectiveNode(
                    name: $contentDir['name'],
                    expression: $contentDir['expression'],
                    children: $transformedChildren,
                    elseChildren: null,
                    line: $node->line,
                    column: $node->column,
                ),
            ];
        }

        // Create element without control flow directive but keep attribute directives
        $wrappedElement = new ElementNode(
            tag: $node->tag,
            attributes: $directives['remaining'],
            children: $transformedChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
        );

        // If there's a control flow directive, wrap everything in it
        if ($directives['controlFlow'] !== null) {
            $controlDir = $directives['controlFlow'];

            return new DirectiveNode(
                name: $controlDir['name'],
                expression: $controlDir['expression'],
                children: [$wrappedElement],
                elseChildren: null,
                line: $node->line,
                column: $node->column,
            );
        }

        // Only content directive - return it directly
        $contentDir = $directives['content'];
        if ($contentDir === null) {
            throw new RuntimeException('No content directive found');
        }

        return new DirectiveNode(
            name: $contentDir['name'],
            expression: $contentDir['expression'],
            children: [$wrappedElement],
            elseChildren: null,
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
            elseChildren: null,
            line: $line,
            column: $column,
        );

        // Compile the directive - attribute directives return nodes that represent attribute output
        $compiledNodes = $compiler->compile($directiveNode);

        // Convert compiled nodes to attribute format
        // Attribute compilers should return appropriate output format
        foreach ($compiledNodes as $node) {
            // Parse the RawPhpNode code to extract attribute name and value
            // Example: class="<php echo classNames(...) >"
            if ($node instanceof RawPhpNode && preg_match('/^(\w+)="(.+)"$/', $node->code, $matches)) {
                $attrName = $matches[1];
                $attrValue = $matches[2];
                // Create OutputNode for the attribute value
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
            }
        }
    }
}
