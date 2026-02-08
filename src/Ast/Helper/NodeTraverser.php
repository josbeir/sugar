<?php
declare(strict_types=1);

namespace Sugar\Ast\Helper;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;

/**
 * AST tree traversal utilities
 *
 * Provides generic tree walking functionality for AST manipulation passes.
 * Simplifies recursive traversal patterns common across compiler passes.
 */
final class NodeTraverser
{
    /**
     * Walk AST nodes and apply visitor function
     *
     * The visitor receives each node and a recurse callback. The visitor can:
     * - Return a single transformed node
     * - Return an array of nodes (for expansion)
     * - Call $recurse($node) to process children recursively
     *
     * Example:
     * ```php
     * NodeTraverser::walk($nodes, function($node, $recurse) {
     *     if ($node instanceof ComponentNode) {
     *         return $this->expandComponent($node); // Return array of nodes
     *     }
     *     return $recurse($node); // Process children
     * });
     * ```
     *
     * @param array<\Sugar\Ast\Node> $nodes Nodes to traverse
     * @param callable(\Sugar\Ast\Node, callable): (\Sugar\Ast\Node|array<\Sugar\Ast\Node>) $visitor Visitor function
     * @return array<\Sugar\Ast\Node> Transformed nodes
     */
    public static function walk(array $nodes, callable $visitor): array
    {
        $result = [];

        foreach ($nodes as $node) {
            // Provide recurse callback that processes children
            $recurse = function (Node $node) use ($visitor): Node {
                if ($node instanceof ElementNode) {
                    $node->children = self::walk($node->children, $visitor);

                    return $node;
                }

                if ($node instanceof FragmentNode) {
                    $node->children = self::walk($node->children, $visitor);

                    return $node;
                }

                return $node;
            };

            $transformed = $visitor($node, $recurse);

            // Support returning single node or array of nodes (for expansion)
            if (is_array($transformed)) {
                array_push($result, ...$transformed);
            } else {
                $result[] = $transformed;
            }
        }

        return $result;
    }

    /**
     * Walk AST depth-first, applying visitor to each node
     *
     * Unlike walk(), this does not transform the tree - it just visits
     * each node for inspection or side effects (e.g., collecting data).
     *
     * Example:
     * ```php
     * $outputNodes = [];
     * NodeTraverser::walkRecursive($ast, function($node) use (&$outputNodes) {
     *     if ($node instanceof OutputNode) {
     *         $outputNodes[] = $node;
     *     }
     * });
     * ```
     *
     * @param \Sugar\Ast\Node $node Root node to start traversal
     * @param callable(\Sugar\Ast\Node): void $visitor Visitor function (no return value)
     */
    public static function walkRecursive(Node $node, callable $visitor): void
    {
        $visitor($node);

        if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof DocumentNode) {
            foreach ($node->children as $child) {
                self::walkRecursive($child, $visitor);
            }
        }
    }

    /**
     * Find first node matching predicate (depth-first search)
     *
     * @param \Sugar\Ast\Node $root Root node to search from
     * @param callable(\Sugar\Ast\Node): bool $predicate Test function
     * @return \Sugar\Ast\Node|null First matching node or null
     */
    public static function findFirst(Node $root, callable $predicate): ?Node
    {
        if ($predicate($root)) {
            return $root;
        }

        if ($root instanceof ElementNode || $root instanceof FragmentNode || $root instanceof DocumentNode) {
            foreach ($root->children as $child) {
                $found = self::findFirst($child, $predicate);
                if ($found instanceof Node) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find all nodes matching predicate (depth-first search)
     *
     * @param \Sugar\Ast\Node $root Root node to search from
     * @param callable(\Sugar\Ast\Node): bool $predicate Test function
     * @return array<\Sugar\Ast\Node> All matching nodes
     */
    public static function findAll(Node $root, callable $predicate): array
    {
        $matches = [];

        self::walkRecursive($root, function (Node $node) use ($predicate, &$matches): void {
            if ($predicate($node)) {
                $matches[] = $node;
            }
        });

        return $matches;
    }

    /**
     * Find the first element node in a document.
     */
    public static function findRootElement(DocumentNode $document): ?ElementNode
    {
        foreach ($document->children as $child) {
            if ($child instanceof ElementNode) {
                return $child;
            }
        }

        return null;
    }
}
