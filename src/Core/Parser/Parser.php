<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node as DomNode;
use Dom\Text;
use RuntimeException;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\ParserConfig;
use Sugar\Core\Enum\OutputContext;

/**
 * Parses template source into AST
 */
final class Parser
{
    /**
     * @param \Sugar\Core\Config\ParserConfig $config Parser configuration
     */
    public function __construct(
        private readonly ParserConfig $config = new ParserConfig(),
    ) {
    }

    /**
     * Parse template source into AST
     *
     * @param string $source Template source code
     * @return \Sugar\Core\Ast\DocumentNode Document AST
     */
    public function parse(string $source): DocumentNode
    {
        if ($source === '') {
            return new DocumentNode([]);
        }

        $nodes = $this->tokenize($source);

        // Stitch together broken HTML fragments across PHP token boundaries
        $nodes = $this->stitchBrokenHtml($nodes);

        // Reconstruct tree across PHP token boundaries
        $nodes = $this->reconstructTree($nodes);

        return new DocumentNode($nodes);
    }

    /**
     * Tokenize source into nodes using PHP's native tokenizer
     *
     * @param string $source Template source
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function tokenize(string $source): array
    {
        $tokens = token_get_all($source);
        $nodes = [];
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            // Inline HTML/text (string in token array)
            if (is_string($token)) {
                $nodes[] = new TextNode($token, 1, 1);
                $i++;
                continue;
            }

            [$id, $text, $line] = $token;

            // T_INLINE_HTML: HTML/text outside PHP tags
            if ($id === T_INLINE_HTML) {
                // Don't parse HTML yet - just store as TextNode for later stitching
                $nodes[] = new TextNode($text, $line, 1);
                $i++;
                continue;
            }

            // T_OPEN_TAG_WITH_ECHO: <?= expression
            if ($id === T_OPEN_TAG_WITH_ECHO) {
                [$expression, $nextIndex] = $this->extractExpression($tokens, $i + 1);
                $nodes[] = new OutputNode(
                    expression: trim($expression),
                    escape: true,
                    context: OutputContext::HTML,
                    line: $line,
                    column: 1,
                );
                $i = $nextIndex;
                continue;
            }

            // T_OPEN_TAG: <?php
            if ($id === T_OPEN_TAG) {
                [$phpCode, $isEcho, $nextIndex] = $this->extractPhpBlock($tokens, $i + 1);

                // Preserve whitespace after <?php (token_get_all includes it in T_OPEN_TAG)
                $whitespaceAfterTag = '';
                if (strlen($text) > 5) { // More than "<?php"
                    $whitespaceAfterTag = substr($text, 5);
                }

                if ($isEcho) {
                    // <?php echo expression - treat as output
                    $nodes[] = new OutputNode(
                        expression: trim($phpCode),
                        escape: true,
                        context: OutputContext::HTML,
                        line: $line,
                        column: 1,
                    );
                } else {
                    // Regular PHP code - pass through with original whitespace
                    $nodes[] = new RawPhpNode(
                        code: $whitespaceAfterTag . $phpCode,
                        line: $line,
                        column: 1,
                    );
                }

                $i = $nextIndex;
                continue;
            }

            $i++;
        }

        return $nodes;
    }

    /**
     * Stitch together broken HTML fragments across PHP token boundaries
     *
     * When PHP tokens (<?= ?>) appear inside HTML tags, the tokenizer breaks
     * the HTML into fragments like: '<a href="', OutputNode, '">text</a>'
     * This method rejoins these fragments and reparses them as complete HTML
     * using PHP 8.4's native Dom\HTMLDocument.
     *
     * @param array<\Sugar\Core\Ast\Node> $nodes Node list with potential broken HTML
     * @return array<\Sugar\Core\Ast\Node> Node list with rejoined HTML
     */
    private function stitchBrokenHtml(array $nodes): array
    {
        $result = [];
        $i = 0;
        $count = count($nodes);

        while ($i < $count) {
            $node = $nodes[$i];

            // Look for TextNode that might be start of broken HTML
            if ($node instanceof TextNode && str_contains($node->content, '<')) {
                // Check if this looks like incomplete HTML (unclosed tag or incomplete tag)
                $content = $node->content;

                // Count opening tags (<tag>) vs closing tags (</tag>)
                // This properly detects fragments like "<h1>" (opening) vs "</h1>" (closing)
                $openingTags = preg_match_all('/<[a-zA-Z][^>]*>/', $content);
                $closingTags = preg_match_all('/<\/[a-zA-Z][^>]*>/', $content);

                // Also check for incomplete tags (e.g., "<img src=" without closing >)
                $hasIncompleteTag = preg_match('/<[a-zA-Z][^>]*$/', $content);

                // DEBUG

                // If we have unclosed opening tags or incomplete tag, this fragment needs stitching
                if ($openingTags > $closingTags || $hasIncompleteTag) {
                    // Collect nodes until we have balanced tags
                    $collected = [$node];
                    $j = $i + 1;
                    $totalOpeningTags = $openingTags;
                    $totalClosingTags = $closingTags;
                    $stillIncomplete = $hasIncompleteTag;

                    while ($j < $count) {
                        $nextNode = $nodes[$j];

                        // Stop if we hit another PURE opening tag (not closing + opening)
                        if ($nextNode instanceof TextNode) {

                            // Check if this starts with an opening tag (not closing tag)
                            if (preg_match('/^<[a-zA-Z]/', $nextNode->content)) {
                                break;
                            }

                            // If we had an incomplete tag, check if this fragment completes it
                            if ($stillIncomplete && str_contains($nextNode->content, '>')) {
                                $stillIncomplete = false;
                                // Now count as a complete opening tag
                                $totalOpeningTags++;
                            }

                            // Count tags in this fragment
                            $nodeOpening = preg_match_all('/<[a-zA-Z][^>]*>/', $nextNode->content);
                            $nodeClosing = preg_match_all('/<\/[a-zA-Z][^>]*>/', $nextNode->content);
                            $totalOpeningTags += $nodeOpening;
                            $totalClosingTags += $nodeClosing;
                        }

                        $collected[] = $nextNode;

                        // Check if we now have balanced tags (or just completed an incomplete tag for void elements)
                        if (($totalOpeningTags === $totalClosingTags && $totalOpeningTags > 0) ||
                            (!$stillIncomplete && $totalOpeningTags === 1 && $totalClosingTags === 0)) {
                            $j++;
                            break;
                        }

                        $j++;
                    }

                    $found = $totalOpeningTags === $totalClosingTags && $totalOpeningTags > 0;

                    if ($found) {
                        // Reconstruct the HTML by joining fragments with placeholders
                        $htmlFragments = [];
                        $nonTextNodes = [];

                        foreach ($collected as $collectedNode) {
                            if ($collectedNode instanceof TextNode) {
                                $htmlFragments[] = $collectedNode->content;
                            } else {
                                // String-based placeholder marker (won't break HTML parsing)
                                $placeholder = '@@SUGAR_' . count($nonTextNodes) . '@@';
                                $htmlFragments[] = $placeholder;
                                $nonTextNodes[] = $collectedNode;
                            }
                        }

                        $htmlString = implode('', $htmlFragments);

                        // Parse with PHP 8.4's Dom\HTMLDocument
                        if (str_contains($htmlString, '<')) {
                            $htmlNodes = $this->parseHtmlWithDom($htmlString, $node->line, $nonTextNodes);
                            array_push($result, ...$htmlNodes);
                        } else {
                            // Not valid HTML, keep as-is
                            array_push($result, ...$collected);
                        }

                        $i = $j;
                        continue;
                    }
                }
            }

            // No stitching needed
            $result[] = $node;
            $i++;
        }

        return $result;
    }

    /**
     * Parse HTML string using PHP 8.4's Dom\HTMLDocument and convert to AST
     *
     * This leverages PHP's native Lexbor HTML5 parser instead of building our own.
     * The HTML string contains placeholder elements (<SugarPlaceholder index="N" />)
     * where PHP expressions were, and we traverse the DOM to replace them back.
     *
     * @param string $html HTML string with placeholder comments
     * @param int $line Line number for error reporting
     * @param array<\Sugar\Core\Ast\Node> $placeholders Nodes to replace placeholders with
     * @return array<\Sugar\Core\Ast\Node> AST nodes
     */
    private function parseHtmlWithDom(string $html, int $line, array $placeholders): array
    {
        // Wrap fragment in full HTML document structure for proper parsing (especially script/style)
        $fullHtml = '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';

        // Parse with native DOM parser
        $doc = HTMLDocument::createFromString($fullHtml);

        // Convert body children to AST nodes
        $result = [];
        foreach ($doc->body->childNodes as $child) {
            $converted = $this->convertDomNodeToAst($child, $line, $placeholders);
            if ($converted !== null) {
                if (is_array($converted)) {
                    array_push($result, ...$converted);
                } else {
                    $result[] = $converted;
                }
            }
        }

        return $result;
    }

    /**
     * Convert a DOM node to our AST Node
     *
     * @param \Dom\Node $domNode DOM node to convert
     * @param int $line Line number for error reporting
     * @param array<\Sugar\Core\Ast\Node> $placeholders Placeholder nodes
     * @return \Sugar\Core\Ast\Node|array<\Sugar\Core\Ast\Node>|null AST node(s) or null to skip
     */
    private function convertDomNodeToAst(DomNode $domNode, int $line, array $placeholders): Node|array|null
    {
        // Element node
        if ($domNode instanceof Element) {
            // Process attributes - check for placeholder markers
            $attributes = [];
            foreach ($domNode->attributes as $attr) {
                $value = $attr->value;

                // Check if attribute value is exactly a placeholder marker
                if (preg_match('/^@@SUGAR_(\d+)@@$/', $value, $matches)) {
                    $index = (int)$matches[1];
                    if (isset($placeholders[$index])) {
                        // Use the placeholder node as the attribute value
                        $attributes[] = new AttributeNode(
                            name: $attr->name,
                            value: $placeholders[$index],
                            line: $line,
                            column: 1,
                        );
                        continue;
                    }
                }

                // Regular attribute with text value
                $attributes[] = new AttributeNode(
                    name: $attr->name,
                    value: $value,
                    line: $line,
                    column: 1,
                );
            }

            // Process children
            $children = [];
            foreach ($domNode->childNodes as $child) {
                $converted = $this->convertDomNodeToAst($child, $line, $placeholders);
                if ($converted !== null) {
                    if (is_array($converted)) {
                        array_push($children, ...$converted);
                    } else {
                        $children[] = $converted;
                    }
                }
            }

            return new ElementNode(
                tag: $domNode->localName,
                attributes: $attributes,
                children: $children,
                selfClosing: $this->config->isVoidElement($domNode->localName),
                line: $line,
                column: 1,
            );
        }

        // Text node - check for placeholder markers
        if ($domNode instanceof Text) {
            $text = $domNode->data;
            if ($text === '') {
                return null;
            }

            // Check if text contains placeholder marker(s)
            if (preg_match('/@@SUGAR_\d+@@/', $text)) {
                // Split by placeholder pattern, keeping delimiters
                $parts = preg_split('/(@@SUGAR_\d+@@)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

                $nodes = [];
                foreach ($parts as $part) {
                    if (preg_match('/^@@SUGAR_(\d+)@@$/', $part, $matches)) {
                        // Replace with actual placeholder node
                        $index = (int)$matches[1];
                        if (isset($placeholders[$index])) {
                            $nodes[] = $placeholders[$index];
                        }
                    } else {
                        // Regular text
                        $nodes[] = new TextNode($part, $line, 1);
                    }
                }

                // Return array of nodes (parent will flatten)
                return count($nodes) > 0 ? (count($nodes) === 1 ? $nodes[0] : $nodes) : null;
            }

            return new TextNode($text, $line, 1);
        }

        // Comment node - skip (we don't use comments for placeholders anymore)
        if ($domNode instanceof Comment) {
            return null;
        }

        // Other node types - skip
        return null;
    }

    /**
     * Reconstruct tree across PHP token boundaries
     *
     * This fixes the limitation where PHP tokens break HTML tree structure.
     * Maintains element stack across all nodes to properly nest content.
     * Also wraps elements with directive attributes in DirectiveNodes.
     * RECURSIVE to handle nested elements.
     *
     * @param array<\Sugar\Core\Ast\Node> $nodes Flat node list
     * @return array<\Sugar\Core\Ast\Node> Tree-structured node list
     */
    private function reconstructTree(array $nodes): array
    {
        /** @var array<\Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\DirectiveNode> $stack */
        $stack = [];
        $result = [];

        foreach ($nodes as $node) {
            // Handle ElementNode - could be opening tag, closing tag, or self-closing
            if ($node instanceof ElementNode) {
                // Check if element already has children (from stitching) before recursion
                $hadChildren = $node->children !== [];

                // First, recursively process this element's children
                if ($node->children !== []) {
                    $node->children = $this->reconstructTree($node->children);
                }

                // Check if this element has a directive attribute
                $directiveAttr = null;
                foreach ($node->attributes as $attr) {
                    if ($this->config->isDirective($attr->name)) {
                        $directiveAttr = $attr;
                        break;
                    }
                }

                // Check if this is a closing tag (empty element with no attributes, no children, not self-closing)
                if (
                    $node->attributes === [] &&
                    $node->children === [] &&
                    !$node->selfClosing
                ) {
                    // Try to match with stack - look for matching tag name
                    $matched = false;
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        $stackNode = $stack[$i];
                        $stackElement = $stackNode instanceof DirectiveNode
                            ? $stackNode->children[0]
                            : $stackNode;

                        if ($stackElement instanceof ElementNode && $stackElement->tag === $node->tag) {
                            // Found match - pop everything up to and including this element
                            array_splice($stack, $i);
                            $matched = true;
                            break;
                        }
                    }

                    // If matched, skip this closing tag node (already handled)
                    // If not matched, treat as text/unknown tag
                    if ($matched) {
                        continue;
                    }
                }

                // Opening tag or self-closing/void element
                if ($node->selfClosing || $this->config->isVoidElement($node->tag)) {
                    // Self-closing or void - wrap in directive if needed, add to container
                    if ($directiveAttr !== null) {
                        $wrappedNode = $this->wrapInDirective($node, $directiveAttr);
                        $this->addToContainer($stack, $result, $wrappedNode);
                    } else {
                        $this->addToContainer($stack, $result, $node);
                    }
                } elseif ($directiveAttr !== null) {
                    // Opening tag - wrap in directive if needed, add to container, push to stack
                    $wrappedNode = $this->wrapInDirective($node, $directiveAttr);
                    $this->addToContainer($stack, $result, $wrappedNode);
                    $stack[] = $wrappedNode;
                } else {
                    $this->addToContainer($stack, $result, $node);
                    // Only push to stack if element didn't already have children (from stitching)
                    // Elements with children are already complete and closed
                    if (!$hadChildren && !$node->selfClosing) {
                        $stack[] = $node;
                    }
                }
            } elseif ($node instanceof DirectiveNode) {
                // Recursively process directive children
                if ($node->children !== []) {
                    $node->children = $this->reconstructTree($node->children);
                }

                // Directive wraps an element - add to current container and push to stack
                $this->addToContainer($stack, $result, $node);
                $stack[] = $node;
            } else {
                // Other nodes (TextNode, OutputNode, RawPhpNode) - add to current container
                $this->addToContainer($stack, $result, $node);
            }
        }

        return $result;
    }

    /**
     * Wrap an ElementNode in a DirectiveNode
     *
     * @param \Sugar\Core\Ast\ElementNode $element Element to wrap
     * @param \Sugar\Core\Ast\AttributeNode $directiveAttr Directive attribute
     * @return \Sugar\Core\Ast\DirectiveNode Wrapped node
     */
    private function wrapInDirective(ElementNode $element, AttributeNode $directiveAttr): DirectiveNode
    {
        // Extract directive name and expression
        $directiveName = $this->config->extractDirectiveName($directiveAttr->name);
        if ($directiveName === null) {
            throw new RuntimeException('Invalid directive name: ' . $directiveAttr->name);
        }

        $expression = is_string($directiveAttr->value) ? $directiveAttr->value : '';

        // Remove directive attribute from element
        $element->attributes = array_values(array_filter(
            $element->attributes,
            fn(AttributeNode $attr): bool => !$this->config->isDirective($attr->name),
        ));

        // Wrap in DirectiveNode
        return new DirectiveNode(
            name: $directiveName,
            expression: $expression,
            children: [$element],
            elseChildren: null,
            line: $element->line,
            column: $element->column,
        );
    }

    /**
     * Check if node represents a closing tag (heuristic)
     *
     * @param \Sugar\Core\Ast\ElementNode $node Element node
     */
    private function isClosingTag(ElementNode $node): bool
    {
        return $node->attributes === [] &&
               $node->children === [] &&
               !$node->selfClosing;
    }

    /**
     * Add node to appropriate container (stack top or result)
     *
     * @param array<\Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\DirectiveNode> $stack Element stack
     * @param array<\Sugar\Core\Ast\Node> $result Result array
     * @param \Sugar\Core\Ast\Node $node Node to add
     */
    private function addToContainer(array &$stack, array &$result, Node $node): void
    {
        if ($stack === []) {
            $result[] = $node;
        } else {
            // Add to top of stack's children
            $parent = $stack[count($stack) - 1];

            if ($parent instanceof DirectiveNode) {
                // DirectiveNode wraps element - add to wrapped element's children
                $element = $parent->children[0];
                if ($element instanceof ElementNode) {
                    $element->children[] = $node;
                }
            } elseif ($parent instanceof ElementNode) {
                $parent->children[] = $node;
            }
        }
    }

    /**
     * Extract expression from <?= tag
     *
     * @param array<int|string|array{int, string, int}> $tokens Token array
     * @param int $start Start index
     * @return array{string, int} [expression, next index]
     */
    private function extractExpression(array $tokens, int $start): array
    {
        $code = '';
        $i = $start;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_string($token)) {
                $code .= $token;
                $i++;
                continue;
            }

            if (!is_array($token)) {
                $i++;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_CLOSE_TAG) {
                return [$code, $i + 1];
            }

            $code .= $text;
            $i++;
        }

        return [$code, $i];
    }

    /**
     * Extract PHP code block and detect echo statements
     *
     * @param array<int|string|array{int, string, int}> $tokens Token array
     * @param int $start Start index
     * @return array{string, bool, int} [code, isEcho, next index]
     */
    private function extractPhpBlock(array $tokens, int $start): array
    {
        $code = '';
        $i = $start;
        $count = count($tokens);
        $isEcho = false;
        $firstNonWhitespace = true;
        $whitespaceBeforeClose = '';

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_string($token)) {
                $code .= $token;
                $whitespaceBeforeClose = '';
                $i++;
                continue;
            }

            if (!is_array($token)) {
                $i++;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_CLOSE_TAG) {
                // Include accumulated whitespace before close tag
                return [$code . $whitespaceBeforeClose, $isEcho, $i + 1];
            }

            // Check if first non-whitespace token is echo
            if ($firstNonWhitespace && $id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $isEcho = ($id === T_ECHO);
                $firstNonWhitespace = false;

                if ($isEcho) {
                    // Skip the 'echo' keyword, return only the expression
                    $i++;
                    continue;
                }
            }

            // Track whitespace before potential close tag
            if ($id === T_WHITESPACE) {
                $whitespaceBeforeClose .= $text;
            } else {
                $code .= $whitespaceBeforeClose . $text;
                $whitespaceBeforeClose = '';
            }

            $i++;
        }

        return [$code . $whitespaceBeforeClose, $isEcho, $i];
    }
}
