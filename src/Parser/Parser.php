<?php
declare(strict_types=1);

namespace Sugar\Parser;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;

final readonly class Parser
{
    /**
     * Parse a Sugar template into an AST
     *
     * @param string $source Template source code
     * @return \Sugar\Ast\DocumentNode The parsed document
     */
    public function parse(string $source): DocumentNode
    {
        $tokens = SugarToken::tokenize($source);
        $nodes = $this->parseTokens($tokens);

        return new DocumentNode($nodes);
    }

    /**
     * Parse tokens into AST nodes
     *
     * @param array<\Sugar\Parser\SugarToken> $tokens Token stream
     * @return array<\Sugar\Ast\Node> AST nodes
     */
    private function parseTokens(array $tokens): array
    {
        $nodes = [];
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->isOutput()) {
                [$expression, $nextIndex] = $this->extractExpression($tokens, $i + 1);
                $nodes[] = new OutputNode(
                    expression: $expression,
                    escape: true,
                    context: OutputContext::HTML,
                    line: $token->line,
                    column: $token->pos,
                );
                $i = $nextIndex;
                continue;
            }

            if ($token->isOpenTag()) {
                [$code, $nextIndex] = $this->extractPhpBlock($tokens, $i + 1);
                $nodes[] = new RawPhpNode($code, $token->line, $token->pos);
                $i = $nextIndex;
                continue;
            }

            if ($token->canIgnore()) {
                $i++;
                continue;
            }

            if ($token->isHtml()) {
                if ($token->containsHtml()) {
                    $htmlNodes = $this->parseHtml($token->content(), $token->line, $token->pos);
                    $nodes = array_merge($nodes, $htmlNodes);
                } else {
                    $nodes[] = new TextNode($token->content(), $token->line, $token->pos);
                }

                $i++;
                continue;
            }

            $i++;
        }

        return $this->buildTree($nodes);
    }

    /**
     * Extract a PHP expression until close tag
     *
     * @param array<\Sugar\Parser\SugarToken> $tokens Token stream
     * @param int $start Starting index
     * @return array{0: string, 1: int} Expression and next index
     */
    private function extractExpression(array $tokens, int $start): array
    {
        $expression = '';
        $i = $start;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->isCloseTag()) {
                $i++;
                break;
            }

            $expression .= $token->content();
            $i++;
        }

        return [trim($expression), $i];
    }

    /**
     * Extract a PHP code block until close tag
     *
     * @param array<\Sugar\Parser\SugarToken> $tokens Token stream
     * @param int $start Starting index
     * @return array{0: string, 1: int} Code block and next index
     */
    private function extractPhpBlock(array $tokens, int $start): array
    {
        $code = '';
        $i = $start;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->isCloseTag()) {
                $i++;
                break;
            }

            $code .= $token->content();
            $i++;
        }

        return [trim($code), $i];
    }

    /**
     * Parse HTML string into flat list of nodes and markers
     *
     * @param string $html HTML content
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Ast\Node|\Sugar\Parser\ClosingTagMarker> Flat node list
     */
    private function parseHtml(string $html, int $line, int $column): array
    {
        $nodes = [];
        $pos = 0;
        $len = strlen($html);

        while ($pos < $len) {
            $tagStart = strpos($html, '<', $pos);

            if ($tagStart === false) {
                // Rest is text
                if ($pos < $len) {
                    $nodes[] = new TextNode(substr($html, $pos), $line, $column);
                }

                break;
            }

            // Text before tag
            if ($tagStart > $pos) {
                $nodes[] = new TextNode(substr($html, $pos, $tagStart - $pos), $line, $column);
            }

            // Check for closing tag
            if (isset($html[$tagStart + 1]) && $html[$tagStart + 1] === '/') {
                [$tagName, $endPos] = $this->extractClosingTag($html, $tagStart);
                $nodes[] = new ClosingTagMarker($tagName);
                $pos = $endPos;
            } elseif (isset($html[$tagStart + 1]) && $html[$tagStart + 1] === '!') {
                // Special cases: <!DOCTYPE>, <!-->, <![CDATA[> - treat as text
                $endPos = strpos($html, '>', $tagStart);
                if ($endPos === false) {
                    $endPos = $len;
                } else {
                    $endPos++;
                }

                $nodes[] = new TextNode(substr($html, $tagStart, $endPos - $tagStart), $line, $column);
                $pos = $endPos;
            } else {
                // Opening or self-closing tag
                [$element, $endPos] = $this->extractOpeningTag($html, $tagStart, $line, $column);
                $nodes[] = $element;
                $pos = $endPos;
            }
        }

        return $nodes;
    }

    /**
     * Extract opening or self-closing HTML tag
     *
     * @param string $html HTML source
     * @param int $start Position of <
     * @param int $line Line number
     * @param int $column Column number
     * @return array{0: \Sugar\Ast\ElementNode, 1: int} Element and position after tag
     */
    private function extractOpeningTag(string $html, int $start, int $line, int $column): array
    {
        $pos = $start + 1;
        $len = strlen($html);
        $tagName = '';

        // Extract tag name
        while ($pos < $len && ctype_alnum($html[$pos])) {
            $tagName .= $html[$pos++];
        }

        // Skip whitespace
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        // Parse attributes
        $attributes = [];
        $selfClosing = false;

        while ($pos < $len) {
            $char = $html[$pos];

            if ($char === '>') {
                $pos++;
                break;
            }

            if ($char === '/' && isset($html[$pos + 1]) && $html[$pos + 1] === '>') {
                $selfClosing = true;
                $pos += 2;
                break;
            }

            if (ctype_space($char)) {
                $pos++;
                continue;
            }

            // Parse attribute
            [$attrName, $attrValue, $pos] = $this->extractAttribute($html, $pos);
            $attributes[] = new AttributeNode($attrName, $attrValue, $line, $column);
        }

        $element = new ElementNode(
            tag: $tagName,
            attributes: $attributes,
            children: [],
            selfClosing: $selfClosing,
            line: $line,
            column: $column,
        );

        return [$element, $pos];
    }

    /**
     * Extract closing HTML tag
     *
     * @param string $html HTML source
     * @param int $start Position of <
     * @return array{0: string, 1: int} Tag name and position after tag
     */
    private function extractClosingTag(string $html, int $start): array
    {
        $pos = $start + 2; // Skip </
        $len = strlen($html);
        $tagName = '';

        while ($pos < $len && ctype_alnum($html[$pos])) {
            $tagName .= $html[$pos++];
        }

        // Skip to >
        while ($pos < $len && $html[$pos] !== '>') {
            $pos++;
        }

        if ($pos < $len) {
            $pos++; // Skip >
        }

        return [$tagName, $pos];
    }

    /**
     * Extract attribute name and value
     *
     * @param string $html HTML source
     * @param int $start Starting position
     * @return array{0: string, 1: string|null, 2: int} Name, value, position
     */
    private function extractAttribute(string $html, int $start): array
    {
        $pos = $start;
        $len = strlen($html);
        $name = '';

        // Extract attribute name (including : and - for s:if, data-attr)
        while ($pos < $len && !in_array($html[$pos], ['=', '>', '/', ' ', "\t", "\n", "\r"], true)) {
            $name .= $html[$pos++];
        }

        // Skip whitespace
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        // No value (boolean attribute)
        if ($pos >= $len || $html[$pos] !== '=') {
            return [$name, null, $pos];
        }

        $pos++; // Skip =

        // Skip whitespace after =
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        if ($pos >= $len) {
            return [$name, '', $pos];
        }

        // Extract value
        $quote = $html[$pos];
        if ($quote === '"' || $quote === "'") {
            $pos++; // Skip opening quote
            $value = '';
            while ($pos < $len && $html[$pos] !== $quote) {
                if ($html[$pos] === '\\' && isset($html[$pos + 1]) && $html[$pos + 1] === $quote) {
                    $value .= $quote;
                    $pos += 2;
                } else {
                    $value .= $html[$pos++];
                }
            }

            if ($pos < $len) {
                $pos++; // Skip closing quote
            }

            return [$name, $value, $pos];
        } else {
            // Unquoted value
            $value = '';
            while ($pos < $len && !in_array($html[$pos], ['>', '/', ' ', "\t", "\n", "\r"], true)) {
                $value .= $html[$pos++];
            }

            return [$name, $value, $pos];
        }
    }

    /**
     * Build tree structure from flat node list
     *
     * @param array<\Sugar\Ast\Node|\Sugar\Parser\ClosingTagMarker> $flatNodes Flat list
     * @return array<\Sugar\Ast\Node> Tree structure
     */
    private function buildTree(array $flatNodes): array
    {
        $root = [];
        $stack = [&$root]; // Start with root in stack

        foreach ($flatNodes as $node) {
            if ($node instanceof ElementNode && !$node->selfClosing) {
                // Opening tag - add to current level
                $stack[count($stack) - 1][] = $node;
                // Push reference to this element's children array onto stack
                $stack[] = &$node->children;
            } elseif ($node instanceof ClosingTagMarker) {
                // Closing tag - pop from stack
                if (count($stack) > 1) {
                    array_pop($stack);
                }
            } else {
                // TextNode, OutputNode, or self-closing element
                $stack[count($stack) - 1][] = $node;
            }
        }

        return $root;
    }
}
