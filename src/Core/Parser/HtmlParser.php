<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\ParserConfig;

/**
 * Parses HTML and builds proper tree structure with parent-child relationships
 *
 * Uses stack-based algorithm to track element nesting and properly
 * associate content with parent elements. Handles directive attributes
 * by wrapping elements in DirectiveNodes.
 */
final class HtmlParser
{
    /**
     * Intermediate representation for building the tree
     *
     * @var array<string, mixed>
     */
    private array $tree = [];

    /**
     * Stack of node paths for tracking nesting
     *
     * @var array<array{path: array<int>, hasDirective: bool, directiveName: string|null, directiveExpr: string|null}>
     */
    private array $stack = [];

    /**
     * @param \Sugar\Core\Config\ParserConfig $config Parser configuration
     */
    public function __construct(
        private readonly ParserConfig $config,
    ) {
    }

    /**
     * Parse HTML string into AST nodes with proper tree structure
     *
     * @param string $html HTML content to parse
     * @param int $line Line number for error reporting
     * @return array<\Sugar\Core\Ast\Node> Array of root-level nodes
     */
    public function parse(string $html, int $line): array
    {
        $this->tree = ['type' => 'root', 'children' => []];
        $this->stack = [];

        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            // Find next tag
            $tagStart = strpos($html, '<', $offset);

            if ($tagStart === false) {
                // No more tags - add remaining text
                $text = substr($html, $offset);
                if ($text !== '') {
                    $this->addChild(new TextNode($text, $line, $offset + 1));
                }

                break;
            }

            // Add text before tag
            if ($tagStart > $offset) {
                $text = substr($html, $offset, $tagStart - $offset);
                if ($text !== '') {
                    $this->addChild(new TextNode($text, $line, $offset + 1));
                }
            }

            // Find tag end
            $tagEnd = $this->findTagEnd($html, $tagStart);
            if ($tagEnd === false) {
                // Malformed tag, treat rest as text
                $text = substr($html, $tagStart);
                $this->addChild(new TextNode($text, $line, $tagStart + 1));
                break;
            }

            $fullTag = substr($html, $tagStart, $tagEnd - $tagStart + 1);
            $tagContent = substr($fullTag, 1, -1); // Remove < and >

            // Check if closing tag
            if (str_starts_with($tagContent, '/')) {
                $this->handleClosingTag($line);
            } else {
                // Opening tag
                $this->handleOpeningTag($tagContent, $line, $tagStart + 1);
            }

            $offset = $tagEnd + 1;
        }

        // Convert array structure to proper AST nodes
        return $this->convertToNodes($this->tree['children']);
    }

    /**
     * Get the current container for new children
     *
     * @return array<mixed>
     */
    private function &getCurrentContainer(): array
    {
        if ($this->stack === []) {
            return $this->tree['children'];
        }

        $path = $this->stack[count($this->stack) - 1]['path'];
        $current = &$this->tree;

        foreach ($path as $index) {
            $current = &$current['children'][$index];
        }

        return $current['children'];
    }

    /**
     * Add a child to the current container
     */
    private function addChild(mixed $child): void
    {
        $container = &$this->getCurrentContainer();
        $container[] = $child;
    }

    /**
     * Handle opening tag
     */
    private function handleOpeningTag(string $tagContent, int $line, int $column): void
    {
        $selfClosing = str_ends_with($tagContent, '/');
        $tagContent = rtrim($tagContent, '/ ');

        // Parse tag name and attributes
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)(.*)/s', $tagContent, $matches)) {
            return;
        }

        $tagName = $matches[1];
        $attrsString = trim($matches[2]);

        // Parse attributes
        $attributes = $this->parseAttributes($attrsString, $line);

        // Check for directive - but KEEP it in attributes for cross-token reconstruction
        $directiveAttr = null;
        foreach ($attributes as $attr) {
            if ($this->config->isDirective($attr->name)) {
                $directiveAttr = $attr;
                break;
            }
        }

        // Create element node with ALL attributes (including directive)
        $node = [
            'type' => 'element',
            'tag' => $tagName,
            'attributes' => $attributes, // Keep all attributes including directives
            'children' => [],
            'line' => $line,
            'column' => $column,
            'selfClosing' => $selfClosing,
        ];

        $isVoid = $this->config->isVoidElement($tagName);

        if ($isVoid || $selfClosing) {
            // Void/self-closing elements have no children
            $this->addChild($node);
        } else {
            // Element can have children - add to current container and push to stack
            $container = &$this->getCurrentContainer();
            $index = count($container);
            $container[] = $node;

            // Build path to this node
            $path = [];
            if ($this->stack !== []) {
                $path = $this->stack[count($this->stack) - 1]['path'];
            }

            $path[] = $index;

            $this->stack[] = [
                'path' => $path,
                'hasDirective' => $directiveAttr !== null,
                'directiveName' => $directiveAttr ? $this->config->extractDirectiveName($directiveAttr->name) : null,
                'directiveExpr' => is_string($directiveAttr?->value) ? $directiveAttr->value : null,
            ];
        }
    }

    /**
     * Handle closing tag
     */
    private function handleClosingTag(int $line): void
    {
        if ($this->stack === []) {
            // Unmatched closing tag - ignore or add as text
            return;
        }

        $stackEntry = array_pop($this->stack);

        // If the element had a directive attribute, wrap it in DirectiveNode
        if ($stackEntry['hasDirective'] && $stackEntry['directiveName'] !== null) {
            // Navigate to parent and get the element we just closed
            $parentPath = array_slice($stackEntry['path'], 0, -1);
            $elementIndex = $stackEntry['path'][count($stackEntry['path']) - 1];

            $parent = &$this->tree['children'];
            foreach ($parentPath as $index) {
                $parent = &$parent[$index]['children'];
            }

            $element = $parent[$elementIndex];

            // Strip directive attribute from element since we're wrapping it
            $element['attributes'] = array_filter(
                $element['attributes'],
                fn($attr): bool => !$this->config->isDirective($attr->name),
            );

            // Replace element with directive wrapping it
            $parent[$elementIndex] = [
                'type' => 'directive',
                'name' => $stackEntry['directiveName'],
                'expression' => $stackEntry['directiveExpr'] ?? '',
                'children' => [$element],
                'line' => $line,
                'column' => 1,
            ];
        }
    }

    /**
     * Parse attributes from attribute string
     *
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function parseAttributes(string $attrsString, int $line): array
    {
        if (trim($attrsString) === '') {
            return [];
        }

        $attrs = [];

        // Match attributes: name="value" or name='value' or name (boolean)
        preg_match_all(
            '/([a-zA-Z:][a-zA-Z0-9:_-]*)\s*(?:=\s*(["\'])([^"\']*)\2)?/i',
            $attrsString,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $name = $match[1];
            $value = $match[3] ?? null;

            $attrs[] = new AttributeNode(
                name: $name,
                value: $value,
                line: $line,
                column: 1,
            );
        }

        return $attrs;
    }

    /**
     * Find the end of a tag, handling quoted strings
     */
    private function findTagEnd(string $html, int $start): int|false
    {
        $inQuote = false;
        $quoteChar = '';
        $length = strlen($html);

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $html[$i];

            if (!$inQuote) {
                if ($char === '"' || $char === "'") {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === '>') {
                    return $i;
                }
            } elseif ($char === $quoteChar && ($html[$i - 1] ?? '') !== '\\') {
                $inQuote = false;
            }
        }

        return false;
    }

    /**
     * Convert array structure to proper AST nodes
     *
     * @param array<array<string, mixed>> $children
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function convertToNodes(array $children): array
    {
        $nodes = [];

        foreach ($children as $child) {
            if ($child instanceof Node) {
                $nodes[] = $child;
                continue;
            }

            if ($child['type'] === 'element') {
                $nodes[] = new ElementNode(
                    tag: $child['tag'],
                    attributes: $child['attributes'],
                    children: $this->convertToNodes($child['children']),
                    selfClosing: $child['selfClosing'],
                    line: $child['line'],
                    column: $child['column'],
                );
            } elseif ($child['type'] === 'directive') {
                $nodes[] = new DirectiveNode(
                    name: $child['name'],
                    expression: $child['expression'],
                    children: $this->convertToNodes($child['children']),
                    elseChildren: null,
                    line: $child['line'],
                    column: $child['column'],
                );
            }
        }

        return $nodes;
    }
}
