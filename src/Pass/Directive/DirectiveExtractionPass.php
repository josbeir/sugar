<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;

/**
 * Extracts directive attributes from elements and creates DirectiveNodes
 *
 * This pass walks the AST looking for elements with s:* attributes
 * (s:if, s:foreach, s:while, etc.) and converts them into DirectiveNode
 * instances. It does NOT compile directives - that's handled by
 * DirectiveCompilationPass.
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
     * @param string $directivePrefix Prefix for directive attributes (default: 's')
     */
    public function __construct(
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
     * Extract directive name and expression from element attributes
     *
     * @return array{name: string, expression: string, remaining: array<\Sugar\Ast\AttributeNode>}
     */
    private function extractDirective(ElementNode $node): array
    {
        $directiveName = null;
        $directiveExpression = null;
        $remainingAttrs = [];

        foreach ($node->attributes as $attr) {
            if (str_starts_with($attr->name, $this->directivePrefix . ':')) {
                $directiveName = substr($attr->name, strlen($this->directivePrefix) + 1);
                // Directive expressions must be strings, not OutputNodes
                if ($attr->value instanceof OutputNode) {
                    throw new RuntimeException('Directive attributes cannot contain dynamic output expressions');
                }

                $directiveExpression = $attr->value ?? 'true';
            } else {
                $remainingAttrs[] = $attr;
            }
        }

        if ($directiveName === null) {
            throw new RuntimeException('No directive attribute found on element');
        }

        if ($directiveExpression === null) {
            throw new RuntimeException('Directive expression cannot be null');
        }

        return [
            'name' => $directiveName,
            'expression' => $directiveExpression,
            'remaining' => $remainingAttrs,
        ];
    }

    /**
     * Transform ElementNode with directive attribute into DirectiveNode
     */
    private function elementToDirective(ElementNode $node): DirectiveNode
    {
        $directive = $this->extractDirective($node);

        // First, transform children recursively to extract nested directives
        $transformedChildren = [];
        foreach ($node->children as $child) {
            if ($child instanceof ElementNode && $this->hasDirectiveAttribute($child)) {
                $transformedChildren[] = $this->elementToDirective($child);
            } else {
                $transformedChildren[] = $this->transformNode($child);
            }
        }

        // Create element without directive attribute as child
        $wrappedElement = new ElementNode(
            tag: $node->tag,
            attributes: $directive['remaining'],
            children: $transformedChildren,
            selfClosing: $node->selfClosing,
            line: $node->line,
            column: $node->column,
        );

        return new DirectiveNode(
            name: $directive['name'],
            expression: $directive['expression'],
            children: [$wrappedElement],
            elseChildren: null,
            line: $node->line,
            column: $node->column,
        );
    }
}
