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
use Sugar\Extension\PairedDirectiveCompilerInterface;

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
        $skipNext = false;

        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            $node = $nodes[$i];

            if ($node instanceof ElementNode && $this->hasDirectiveAttribute($node)) {
                // Check if this directive needs pairing with next sibling
                $pairingDirectiveName = $this->getPairingDirectiveName($node);

                if ($pairingDirectiveName !== null && isset($nodes[$i + 1])) {
                    $nextNode = $nodes[$i + 1];
                    if (
                        $nextNode instanceof ElementNode &&
                        $this->hasSpecificDirective($nextNode, $pairingDirectiveName)
                    ) {
                        // Pair the directives
                        $result[] = $this->elementToDirectiveWithPairing($node, $nextNode);
                        $skipNext = true;
                        continue;
                    }
                }

                // Convert element with directive into DirectiveNode
                $result[] = $this->elementToDirective($node);
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

    /**
     * Get the pairing directive name for an element
     *
     * Checks if the element has a control flow directive that requires pairing.
     *
     * @return string|null The name of the pairing directive, or null if no pairing needed
     */
    private function getPairingDirectiveName(ElementNode $node): ?string
    {
        foreach ($node->attributes as $attr) {
            if (str_starts_with($attr->name, $this->directivePrefix . ':')) {
                $name = substr($attr->name, strlen($this->directivePrefix) + 1);

                try {
                    $compiler = $this->registry->getDirective($name);
                    if (
                        $compiler->getType() === DirectiveType::CONTROL_FLOW &&
                        $compiler instanceof PairedDirectiveCompilerInterface
                    ) {
                        return $compiler->getPairingDirective();
                    }
                } catch (RuntimeException) {
                    // Directive not found, continue
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Check if an element has a specific directive
     *
     * @param string $directiveName The directive name (without prefix)
     */
    private function hasSpecificDirective(ElementNode $node, string $directiveName): bool
    {
        foreach ($node->attributes as $attr) {
            if ($attr->name === $this->directivePrefix . ':' . $directiveName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Transform ElementNode with paired directive into DirectiveNode
     *
     * Handles directives that have a pairing sibling (e.g., forelse + empty).
     * Populates the DirectiveNode's elseChildren property with the paired element's content.
     */
    private function elementToDirectiveWithPairing(ElementNode $primaryNode, ElementNode $pairNode): DirectiveNode
    {
        // Extract directives from primary node
        $directives = $this->extractDirective($primaryNode);

        if ($directives['controlFlow'] === null) {
            throw new RuntimeException('Expected control flow directive for pairing');
        }

        // Transform primary node children recursively
        $transformedChildren = $this->transformChildrenForDirective($primaryNode);

        // If there's a content directive, wrap it
        if ($directives['content'] !== null) {
            $contentDir = $directives['content'];
            $transformedChildren = [
                new DirectiveNode(
                    name: $contentDir['name'],
                    expression: $contentDir['expression'],
                    children: $transformedChildren,
                    elseChildren: null,
                    line: $primaryNode->line,
                    column: $primaryNode->column,
                ),
            ];
        }

        // Create element for primary body
        $wrappedElement = new ElementNode(
            tag: $primaryNode->tag,
            attributes: $directives['remaining'],
            children: $transformedChildren,
            selfClosing: $primaryNode->selfClosing,
            line: $primaryNode->line,
            column: $primaryNode->column,
        );

        // Transform pair node children recursively
        $transformedPairChildren = $this->transformChildrenForDirective($pairNode);

        // Get pairing directive name from the compiler
        $pairingDirectiveName = $directives['controlFlow']['name'];
        $pairCompiler = $this->registry->getDirective($pairingDirectiveName);

        if (!$pairCompiler instanceof PairedDirectiveCompilerInterface) {
            throw new RuntimeException('Expected paired directive compiler');
        }

        $pairDirectiveName = $pairCompiler->getPairingDirective();

        // Create element for pair body (without the pairing directive attribute)
        $pairElement = new ElementNode(
            tag: $pairNode->tag,
            attributes: array_filter(
                $pairNode->attributes,
                fn($attr) => $attr->name !== $this->directivePrefix . ':' . $pairDirectiveName,
            ),
            children: $transformedPairChildren,
            selfClosing: $pairNode->selfClosing,
            line: $pairNode->line,
            column: $pairNode->column,
        );

        // Create DirectiveNode with elseChildren populated
        $controlDir = $directives['controlFlow'];

        return new DirectiveNode(
            name: $controlDir['name'],
            expression: $controlDir['expression'],
            children: [$wrappedElement],
            elseChildren: [$pairElement],
            line: $primaryNode->line,
            column: $primaryNode->column,
        );
    }

    /**
     * Transform children of an element node for directive extraction
     *
     * @param \Sugar\Ast\ElementNode $node
     * @return array<\Sugar\Ast\Node>
     */
    private function transformChildrenForDirective(ElementNode $node): array
    {
        return $this->transformChildren($node->children);
    }
}
