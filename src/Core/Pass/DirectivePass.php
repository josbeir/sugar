<?php
declare(strict_types=1);

namespace Sugar\Core\Pass;

use RuntimeException;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Transforms DirectiveNodes into PHP control structures
 *
 * Converts template directives (s:if, s:foreach, s:while) into their
 * PHP equivalents while preserving child node structure.
 */
final class DirectivePass
{
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
            $newChildren = [];
            foreach ($node->children as $child) {
                if ($child instanceof DirectiveNode) {
                    array_push($newChildren, ...$this->transformDirective($child));
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
}
