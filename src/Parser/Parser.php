<?php
declare(strict_types=1);

namespace Sugar\Parser;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Enum\OutputContext;
use Sugar\Runtime\HtmlTagHelper;

final readonly class Parser
{
    private SugarConfig $config;

    private DirectivePrefixHelper $prefixHelper;

    /**
     * Constructor
     *
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(?SugarConfig $config = null)
    {
        $this->config = $config ?? new SugarConfig();
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
    }

    /**
     * Parse a Sugar template into an AST
     *
     * @param string $source Template source code
     * @return \Sugar\Ast\DocumentNode The parsed document
     */
    public function parse(string $source): DocumentNode
    {
        $tokens = SugarToken::tokenize($source);
        $nodes = $this->parseTokens($tokens, $source);

        return new DocumentNode($nodes);
    }

    /**
     * Parse tokens into AST nodes
     *
     * @param array<\Sugar\Parser\SugarToken> $tokens Token stream
     * @param string $source Template source code
     * @return array<\Sugar\Ast\Node> AST nodes
     */
    private function parseTokens(array $tokens, string $source): array
    {
        $nodes = [];
        $i = 0;
        $count = count($tokens);
        $pendingAttribute = null;
        $pendingAttributeContinuation = null;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->isOutput()) {
                $column = $this->columnFromOffset($source, $token->pos);
                [$expression, $nextIndex] = $this->extractExpression($tokens, $i + 1);
                $expression = $this->normalizeOutputExpression($expression);

                // Parse pipe syntax if present
                $pipes = $this->parsePipes($expression);
                $finalExpression = $pipes['expression'];
                $pipeChain = $pipes['pipes'];
                $shouldEscape = !$pipes['raw'];
                $outputContext = $pipes['raw'] ? OutputContext::RAW : OutputContext::HTML;

                $outputNode = new OutputNode(
                    expression: $finalExpression,
                    escape: $shouldEscape,
                    context: $outputContext,
                    line: $token->line,
                    column: $column,
                    pipes: $pipeChain,
                );

                if (is_array($pendingAttribute)) {
                    $element = $pendingAttribute['element'];
                    $attrIndex = $pendingAttribute['attrIndex'];
                    $element->attributes[$attrIndex]->value = $outputNode;
                    $pendingAttributeContinuation = $element;
                    $pendingAttribute = null;
                    $i = $nextIndex;
                    continue;
                }

                $nodes[] = $outputNode;
                $i = $nextIndex;
                continue;
            }

            if ($token->isOpenTag()) {
                $column = $this->columnFromOffset($source, $token->pos);
                [$code, $nextIndex] = $this->extractPhpBlock($tokens, $i + 1);
                $nodes[] = new RawPhpNode($code, $token->line, $column);
                $i = $nextIndex;
                continue;
            }

            if ($token->canIgnore()) {
                $i++;
                continue;
            }

            if ($token->isHtml()) {
                $column = $this->columnFromOffset($source, $token->pos);
                $html = $token->content();
                if ($pendingAttributeContinuation instanceof ElementNode) {
                    $html = $this->applyAttributeContinuation($html, $pendingAttributeContinuation);
                    $pendingAttributeContinuation = null;
                }

                if (str_contains($html, '<') || str_contains($html, '>')) {
                    $htmlNodes = $this->parseHtml($html, $token->line, $column);
                    $nodes = array_merge($nodes, $htmlNodes);
                    if ($pendingAttribute === null) {
                        $pendingAttribute = $this->detectOpenAttribute($html, $htmlNodes);
                    }
                } else {
                    $nodes[] = new TextNode($html, $token->line, $column);
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
     * Normalize output expression to support trailing semicolons.
     */
    private function normalizeOutputExpression(string $expression): string
    {
        $expression = trim($expression);
        if ($expression !== '' && str_ends_with($expression, ';')) {
            return rtrim($expression, " \t\n\r\0\x0B;");
        }

        return $expression;
    }

    /**
     * Convert an absolute offset into a 1-based column index.
     */
    private function columnFromOffset(string $source, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        $before = substr($source, 0, $offset);
        $lastNewline = strrpos($before, "\n");
        if ($lastNewline === false) {
            return $offset + 1;
        }

        return $offset - $lastNewline;
    }

    /**
     * Parse pipe syntax from expression
     *
     * Delegates to PipeParser utility for DRY implementation.
     *
     * @param string $expression The expression to parse
     * @return array{expression: string, pipes: array<string>|null, raw: bool} Base expression, pipe chain, and raw flag
     */
    private function parsePipes(string $expression): array
    {
        return PipeParser::parse($expression);
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
     * @return array{0: \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode|\Sugar\Ast\ComponentNode, 1: int} Element, Fragment, or Component and position after tag
     */
    private function extractOpeningTag(string $html, int $start, int $line, int $column): array
    {
        $pos = $start + 1;
        $len = strlen($html);
        $tagName = '';

        // Extract tag name (alphanumeric + hyphens for custom elements like s-template)
        while ($pos < $len && (ctype_alnum($html[$pos]) || $html[$pos] === '-')) {
            $tagName .= $html[$pos++];
        }

        // Skip whitespace
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        // Parse attributes
        $attributes = [];
        $selfClosing = false;
        $elementColumn = $column + $start;

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
            $attrStart = $pos;
            [$attrName, $attrValue, $pos] = $this->extractAttribute($html, $pos);
            $attrColumn = $column + $attrStart;
            $attributes[] = new AttributeNode($attrName, $attrValue, $line, $attrColumn);
        }

        $isFragment = $tagName === $this->config->getFragmentElement();
        $isComponent = $this->prefixHelper->hasElementPrefix($tagName) && !$isFragment;

        if (
            !$selfClosing
            && !$isFragment
            && !$isComponent
            && HtmlTagHelper::isSelfClosing($tagName, $this->config->selfClosingTags)
        ) {
            $selfClosing = true;
        }

        $element = new ElementNode(
            tag: $tagName,
            attributes: $attributes,
            children: [],
            selfClosing: $selfClosing,
            line: $line,
            column: $elementColumn,
        );

        // Handle fragment element (e.g., <s-template>, <x-template>)
        if ($isFragment) {
            $element = new FragmentNode(
                attributes: $attributes,
                children: [],
                line: $line,
                column: $elementColumn,
                selfClosing: $selfClosing,
            );
        }

        // Handle component elements (e.g., <s-button>, <x-alert>)
        // Components start with elementPrefix but are NOT the fragment element
        if ($isComponent) {
            $componentName = $this->prefixHelper->stripElementPrefix($tagName);
            $element = new ComponentNode(
                name: $componentName,
                attributes: $attributes,
                children: [],
                line: $line,
                column: $elementColumn,
            );
        }

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

        // Extract tag name (alphanumeric + hyphens for custom elements)
        while ($pos < $len && (ctype_alnum($html[$pos]) || $html[$pos] === '-')) {
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
        }

        // Unquoted value
        $value = '';
        while ($pos < $len && !in_array($html[$pos], ['>', '/', ' ', "\t", "\n", "\r"], true)) {
            $value .= $html[$pos++];
        }

        return [$name, $value, $pos];
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
            } elseif ($node instanceof FragmentNode && !$node->selfClosing) {
                // Fragment node - treat like element when not self-closing
                $stack[count($stack) - 1][] = $node;
                // Push reference to this fragment's children array onto stack
                $stack[] = &$node->children;
            } elseif ($node instanceof FragmentNode) {
                $stack[count($stack) - 1][] = $node;
            } elseif ($node instanceof ComponentNode) {
                // Component node - treat like element (non-self-closing)
                $stack[count($stack) - 1][] = $node;
                // Push reference to this component's children array onto stack
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

    /**
     * Detect if the HTML fragment ends with an open attribute quote.
     *
     * @param string $html HTML fragment
     * @param array<\Sugar\Ast\Node|\Sugar\Parser\ClosingTagMarker> $htmlNodes Parsed nodes
     * @return array{element: \Sugar\Ast\ElementNode, attrIndex: int}|null
     */
    private function detectOpenAttribute(string $html, array $htmlNodes): ?array
    {
        if (preg_match("/([A-Za-z_:][\\w:.-]*)\\s*=\\s*([\"'])\\s*$/", $html, $matches) !== 1) {
            return null;
        }

        $attrName = $matches[1];

        $element = null;
        foreach (array_reverse($htmlNodes) as $node) {
            if ($node instanceof ElementNode) {
                $element = $node;
                break;
            }
        }

        if (!$element instanceof ElementNode) {
            return null;
        }

        foreach ($element->attributes as $index => $attr) {
            if ($attr->name === $attrName) {
                return ['element' => $element, 'attrIndex' => $index];
            }
        }

        return null;
    }

    /**
     * Continue parsing attributes after an inline output attribute value.
     */
    private function applyAttributeContinuation(string $html, ElementNode $element): string
    {
        $pos = 0;
        $len = strlen($html);

        if ($pos < $len && ($html[$pos] === '"' || $html[$pos] === "'")) {
            $pos++;
        }

        while ($pos < $len) {
            $char = $html[$pos];

            if ($char === '>') {
                $pos++;
                break;
            }

            if ($char === '/' && isset($html[$pos + 1]) && $html[$pos + 1] === '>') {
                $element->selfClosing = true;
                $pos += 2;
                break;
            }

            if (ctype_space($char)) {
                $pos++;
                continue;
            }

            $name = '';
            while ($pos < $len && !in_array($html[$pos], ['=', '>', '/', ' ', "\t", "\n", "\r"], true)) {
                $name .= $html[$pos++];
            }

            if ($name === '') {
                break;
            }

            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }

            $value = null;
            if ($pos < $len && $html[$pos] === '=') {
                $pos++;

                while ($pos < $len && ctype_space($html[$pos])) {
                    $pos++;
                }

                if ($pos < $len) {
                    $quote = $html[$pos];
                    if ($quote === '"' || $quote === "'") {
                        $pos++;
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
                            $pos++;
                        }
                    } else {
                        $value = '';
                        while ($pos < $len && !in_array($html[$pos], ['>', '/', ' ', "\t", "\n", "\r"], true)) {
                            $value .= $html[$pos++];
                        }
                    }
                } else {
                    $value = '';
                }
            }

            $element->attributes[] = new AttributeNode($name, $value, $element->line, $element->column);
        }

        return substr($html, $pos);
    }
}
