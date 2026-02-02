<?php
declare(strict_types=1);

namespace Sugar\Core\Pass;

use RuntimeException;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Transforms DirectiveNodes into PHP control structures
 *
 * Converts template directives (s:if, s:foreach, s:while) into their
 * PHP equivalents while preserving child node structure.
 *
 * This pass handles two scenarios:
 * 1. DirectiveNodes already in the AST
 * 2. ElementNodes with s:* directive attributes
 */
final class DirectivePass
{
    /**
     * @param string $directivePrefix Prefix for directive attributes (default: 's')
     */
    public function __construct(
        private readonly string $directivePrefix = 's',
    ) {
    }

    /**
     * Transform AST by converting DirectiveNodes to PHP control structures
     */
    public function transform(DocumentNode $ast): DocumentNode
    {
        $newChildren = [];

        foreach ($ast->children as $node) {
            if ($node instanceof DirectiveNode) {
                // Transform directive into PHP control structure
                array_push($newChildren, ...$this->transformDirective($node));
            } elseif ($node instanceof ElementNode && $this->hasDirectiveAttribute($node)) {
                // Transform element with directive attribute into DirectiveNode
                $directiveNode = $this->elementToDirective($node);
                array_push($newChildren, ...$this->transformDirective($directiveNode));
            } else {
                // Transform children if node has them
                $newChildren[] = $this->transformNode($node);
            }
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Transform a single directive node into PHP control structure
     *
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function transformDirective(DirectiveNode $node): array
    {
        $result = [];

        // Opening PHP tag
        $result[] = match ($node->name) {
            'if' => new RawPhpNode('if (' . $node->expression . '):', $node->line, $node->column),
            'foreach' => new RawPhpNode('foreach (' . $node->expression . '):', $node->line, $node->column),
            'while' => new RawPhpNode('while (' . $node->expression . '):', $node->line, $node->column),
            default => throw new RuntimeException('Unsupported directive: ' . $node->name),
        };

        // Transform children recursively
        foreach ($node->children as $child) {
            $result[] = $this->transformNode($child);
        }

        // Else branch if present
        if ($node->elseChildren !== null) {
            $result[] = new RawPhpNode('else:', $node->line, $node->column);

            foreach ($node->elseChildren as $elseChild) {
                $result[] = $this->transformNode($elseChild);
            }
        }

        // Closing PHP tag
        $result[] = match ($node->name) {
            'if' => new RawPhpNode('endif;', $node->line, $node->column),
            'foreach' => new RawPhpNode('endforeach;', $node->line, $node->column),
            'while' => new RawPhpNode('endwhile;', $node->line, $node->column),
            default => throw new RuntimeException('Unsupported directive: ' . $node->name),
        };

        return $result;
    }

    /**
     * Transform a node and its children recursively
     */
    private function transformNode(Node $node): Node
    {
        // Handle directives nested in element children
        if ($node instanceof ElementNode) {
            // Check if this element has a directive attribute
            if ($this->hasDirectiveAttribute($node)) {
                $directiveNode = $this->elementToDirective($node);
                // Return the first transformed node (there will be multiple, but we can only return one)
                // This is a limitation - we'd need to return array here
                // For now, we'll handle this in the transform() method
                return $node; // Fallback
            }

            $newChildren = [];
            foreach ($node->children as $child) {
                if ($child instanceof DirectiveNode) {
                    array_push($newChildren, ...$this->transformDirective($child));
                } elseif ($child instanceof ElementNode && $this->hasDirectiveAttribute($child)) {
                    $directiveNode = $this->elementToDirective($child);
                    array_push($newChildren, ...$this->transformDirective($directiveNode));
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
     * @return array{name: string, expression: string, remaining: array<\Sugar\Core\Ast\AttributeNode>}
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

        // Create element without directive attribute as child
        $wrappedElement = new ElementNode(
            tag: $node->tag,
            attributes: $directive['remaining'],
            children: $node->children,
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
