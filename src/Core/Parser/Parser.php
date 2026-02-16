<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Parser\Helper\PipeParser;

/**
 * Recursive-descent parser that transforms a TokenStream into an AST.
 *
 * Works hand-in-hand with the state-machine Lexer:
 *   1. Lexer tokenizes the template source into a flat Token array.
 *   2. This parser consumes tokens via a TokenStream and builds the tree directly
 *      (no flat-then-tree reconstruction, no ClosingTagMarker).
 *
 * The produced AST is identical to the previous parser's output:
 *   DocumentNode → ElementNode / ComponentNode / FragmentNode / TextNode /
 *                  OutputNode / RawPhpNode / RawBodyNode.
 *
 * Example:
 *   $parser = new Parser();
 *   $doc = $parser->parse('<div s:if="$show">...</div>');
 */
final readonly class Parser
{
    private SugarConfig $config;

    private DirectivePrefixHelper $prefixHelper;

    private Lexer $lexer;

    /**
     * @param \Sugar\Core\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(?SugarConfig $config = null)
    {
        $this->config = $config ?? new SugarConfig();
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
        $this->lexer = new Lexer($this->config);
    }

    /**
     * Parse a Sugar template into an AST.
     *
     * @param string $source Template source code
     * @return \Sugar\Core\Ast\DocumentNode The parsed document
     */
    public function parse(string $source): DocumentNode
    {
        $tokens = $this->lexer->tokenize($source);
        $stream = new TokenStream($tokens);

        $children = $this->parseChildren($stream, null);

        return new DocumentNode($children);
    }

    /**
     * Parse children until we encounter a closing tag for `$parentTag` or Eof.
     *
     * @param \Sugar\Core\Parser\TokenStream $stream Token stream
     * @param string|null $parentTag If non-null, stop when we see `</parentTag>`
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function parseChildren(TokenStream $stream, ?string $parentTag): array
    {
        $children = [];

        while (!$stream->isEof()) {
            $token = $stream->current();

            // Check for closing tag of parent
            if ($parentTag !== null && $token->type === TokenType::TagOpen) {
                $next = $stream->peek();
                if ($next->type === TokenType::Slash) {
                    // This is a closing tag — stop recursion, let parent consume it
                    break;
                }
            }

            $node = $this->parseNode($stream);
            if ($node instanceof Node) {
                $children[] = $node;
            }
        }

        return $children;
    }

    /**
     * Parse a single node from the stream.
     */
    private function parseNode(TokenStream $stream): ?Node
    {
        $token = $stream->current();

        return match ($token->type) {
            TokenType::Text => $this->parseText($stream),
            TokenType::Comment => $this->parseComment($stream),
            TokenType::SpecialTag => $this->parseSpecialTag($stream),
            TokenType::TagOpen => $this->parseTag($stream),
            TokenType::PhpOutputOpen => $this->parsePhpOutput($stream, false),
            TokenType::PhpBlockOpen => $this->parsePhpBlock($stream),
            TokenType::RawBody => $this->parseRawBody($stream),
            default => $this->skipToken($stream),
        };
    }

    /**
     * Parse a text token into a TextNode.
     */
    private function parseText(TokenStream $stream): TextNode
    {
        $token = $stream->consume();

        return new TextNode($token->value, $token->line, $token->column);
    }

    /**
     * Parse an HTML comment into a TextNode (preserved as-is).
     */
    private function parseComment(TokenStream $stream): TextNode
    {
        $token = $stream->consume();

        return new TextNode($token->value, $token->line, $token->column);
    }

    /**
     * Parse a special tag (DOCTYPE, CDATA) into a TextNode.
     */
    private function parseSpecialTag(TokenStream $stream): TextNode
    {
        $token = $stream->consume();

        return new TextNode($token->value, $token->line, $token->column);
    }

    /**
     * Parse an HTML tag (open or close).
     */
    private function parseTag(TokenStream $stream): ?Node
    {
        $tagOpenToken = $stream->consume(); // TagOpen `<`

        // Check for closing tag
        if ($stream->current()->type === TokenType::Slash) {
            // This is a closing tag — consume slash, tag name, close
            $stream->consume(); // Slash
            $stream->consumeIf(TokenType::TagName);
            $stream->consumeIf(TokenType::TagClose);

            // Return null — the parent's parseChildren already stopped
            return null;
        }

        // Opening tag — read tag name
        $tagNameToken = $stream->consumeIf(TokenType::TagName);
        $tagName = $tagNameToken instanceof Token ? $tagNameToken->value : '';
        $tagLine = $tagOpenToken->line;
        $tagColumn = $tagOpenToken->column;

        // Read attributes
        $attributes = $this->parseAttributes($stream);

        // Strip the raw directive attribute — for non-self-closing elements the Lexer's
        // raw region pre-scanner already handles this, but self-closing/void tags bypass
        // that path and still carry the attribute.
        $attributes = $this->stripRawAttribute($attributes);

        // Read tag close
        $tagCloseToken = $stream->consumeIf(TokenType::TagClose);
        $selfClosing = $tagCloseToken instanceof Token && $tagCloseToken->value === '/>';

        // Determine node type: fragment, component, or element
        $fragmentElement = $this->config->getFragmentElement();

        if ($tagName === $fragmentElement) {
            return $this->buildFragmentNode($stream, $attributes, $tagLine, $tagColumn, $selfClosing, $tagName);
        }

        if ($this->prefixHelper->hasElementPrefix($tagName)) {
            $componentName = $this->prefixHelper->stripElementPrefix($tagName);

            return $this->buildComponentNode($stream, $componentName, $attributes, $tagLine, $tagColumn, $tagName);
        }

        return $this->buildElementNode($stream, $tagName, $attributes, $tagLine, $tagColumn, $selfClosing);
    }

    /**
     * Build a FragmentNode, parsing children if not self-closing.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     */
    private function buildFragmentNode(
        TokenStream $stream,
        array $attributes,
        int $line,
        int $column,
        bool $selfClosing,
        string $tagName,
    ): FragmentNode {
        $children = [];
        if (!$selfClosing) {
            $children = $this->parseChildren($stream, $tagName);
            $this->consumeClosingTag($stream);
        }

        return new FragmentNode(
            attributes: $attributes,
            children: $children,
            line: $line,
            column: $column,
            selfClosing: $selfClosing,
        );
    }

    /**
     * Build a ComponentNode, parsing children until its closing tag.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     */
    private function buildComponentNode(
        TokenStream $stream,
        string $componentName,
        array $attributes,
        int $line,
        int $column,
        string $tagName,
    ): ComponentNode {
        $children = $this->parseChildren($stream, $tagName);
        $this->consumeClosingTag($stream);

        return new ComponentNode(
            name: $componentName,
            attributes: $attributes,
            children: $children,
            line: $line,
            column: $column,
        );
    }

    /**
     * Build an ElementNode, parsing children if not self-closing.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     */
    private function buildElementNode(
        TokenStream $stream,
        string $tagName,
        array $attributes,
        int $line,
        int $column,
        bool $selfClosing,
    ): ElementNode {
        $children = [];
        if (!$selfClosing) {
            $children = $this->parseChildren($stream, $tagName);
            $this->consumeClosingTag($stream);
        }

        return new ElementNode(
            tag: $tagName,
            attributes: $attributes,
            children: $children,
            selfClosing: $selfClosing,
            line: $line,
            column: $column,
        );
    }

    /**
     * Consume a closing tag sequence: `< / tagName >`
     */
    private function consumeClosingTag(TokenStream $stream): void
    {
        if ($stream->current()->type !== TokenType::TagOpen) {
            return;
        }

        $next = $stream->peek();
        if ($next->type !== TokenType::Slash) {
            return;
        }

        $stream->consume(); // TagOpen
        $stream->consume(); // Slash
        $stream->consumeIf(TokenType::TagName);
        $stream->consumeIf(TokenType::TagClose);
    }

    /**
     * Parse attributes from the token stream.
     *
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function parseAttributes(TokenStream $stream): array
    {
        $attributes = [];

        while ($stream->current()->type === TokenType::AttributeName) {
            $nameToken = $stream->consume();
            $name = $nameToken->value;
            $line = $nameToken->line;
            $column = $nameToken->column;

            // Check for `=`
            if ($stream->consumeIf(TokenType::Equals) instanceof Token) {
                $value = $this->parseAttributeValue($stream);
            } else {
                $value = AttributeValue::boolean();
            }

            $attributes[] = new AttributeNode($name, $value, $line, $column);
        }

        return $attributes;
    }

    /**
     * Parse an attribute value: quoted (possibly with embedded PHP), unquoted, or PHP expression.
     */
    private function parseAttributeValue(TokenStream $stream): AttributeValue
    {
        $current = $stream->current();

        // Unquoted value
        if ($current->type === TokenType::AttributeValueUnquoted) {
            $token = $stream->consume();

            return AttributeValue::static($token->value);
        }

        // PHP expression directly as value (unquoted)
        if ($current->type === TokenType::PhpOutputOpen) {
            $outputNode = $this->parsePhpOutput($stream, true);

            return AttributeValue::output($outputNode);
        }

        // Quoted value
        if ($current->type === TokenType::QuoteOpen) {
            return $this->parseQuotedAttributeValue($stream);
        }

        // Empty value (e.g. data-empty=)
        return AttributeValue::static('');
    }

    /**
     * Parse a quoted attribute value, handling embedded PHP expressions.
     *
     * Produces one of:
     *  - AttributeValue::static() when only text
     *  - AttributeValue::output() when only one PHP expression
     *  - AttributeValue::parts() when mixed text + PHP expressions
     */
    private function parseQuotedAttributeValue(TokenStream $stream): AttributeValue
    {
        $stream->consume(); // QuoteOpen

        /** @var array<string|\Sugar\Core\Ast\OutputNode> $parts */
        $parts = [];

        while ($stream->current()->type !== TokenType::QuoteClose && !$stream->isEof()) {
            if ($stream->current()->type === TokenType::AttributeText) {
                $textToken = $stream->consume();
                // Handle escaped quotes
                $text = str_replace(['\"', "\'"], ['"', "'"], $textToken->value);
                $parts[] = $text;
            } elseif ($stream->current()->type === TokenType::PhpOutputOpen) {
                $outputNode = $this->parsePhpOutput($stream, true);
                $parts[] = $outputNode;
            } else {
                // Unexpected token inside quoted value, consume and skip
                $stream->consume();
            }
        }

        $stream->consumeIf(TokenType::QuoteClose);

        return $this->collapseAttributeParts($parts);
    }

    /**
     * Collapse attribute parts into the simplest possible AttributeValue.
     *
     * @param array<string|\Sugar\Core\Ast\OutputNode> $parts
     */
    private function collapseAttributeParts(array $parts): AttributeValue
    {
        if ($parts === []) {
            return AttributeValue::static('');
        }

        // Single static string
        if (count($parts) === 1 && is_string($parts[0])) {
            return AttributeValue::static($parts[0]);
        }

        // Single output expression
        if (count($parts) === 1 && $parts[0] instanceof OutputNode) {
            return AttributeValue::output($parts[0]);
        }

        // Mixed parts
        return AttributeValue::parts(array_values($parts));
    }

    /**
     * Parse a PHP short echo output expression.
     *
     * @param bool $inAttribute Whether this output is inside an attribute value
     */
    private function parsePhpOutput(TokenStream $stream, bool $inAttribute): OutputNode
    {
        $openToken = $stream->consume(); // PhpOutputOpen
        $line = $openToken->line;
        $column = $openToken->column;

        $expression = '';
        if ($stream->current()->type === TokenType::PhpExpression) {
            $exprToken = $stream->consume();
            $expression = $exprToken->value;
        }

        $stream->consumeIf(TokenType::PhpClose);

        // Normalize: strip trailing semicolons
        $expression = $this->normalizeExpression($expression);

        // Parse pipe syntax
        $pipes = PipeParser::parse($expression);
        $finalExpression = $pipes['expression'];
        $pipeChain = $pipes['pipes'];
        $shouldEscape = !$pipes['raw'];

        if ($pipes['raw']) {
            $outputContext = OutputContext::RAW;
        } elseif ($pipes['json']) {
            $outputContext = $inAttribute ? OutputContext::JSON_ATTRIBUTE : OutputContext::JSON;
        } else {
            $outputContext = $inAttribute ? OutputContext::HTML_ATTRIBUTE : OutputContext::HTML;
        }

        return new OutputNode(
            expression: $finalExpression,
            escape: $shouldEscape,
            context: $outputContext,
            line: $line,
            column: $column,
            pipes: $pipeChain,
        );
    }

    /**
     * Parse a PHP code block (full open tag).
     */
    private function parsePhpBlock(TokenStream $stream): RawPhpNode
    {
        $openToken = $stream->consume(); // PhpBlockOpen
        $line = $openToken->line;
        $column = $openToken->column;

        $code = '';
        if ($stream->current()->type === TokenType::PhpCode) {
            $codeToken = $stream->consume();
            $code = $codeToken->value;
        }

        $stream->consumeIf(TokenType::PhpClose);

        return new RawPhpNode(trim($code), $line, $column);
    }

    /**
     * Parse a raw body token into a RawBodyNode.
     */
    private function parseRawBody(TokenStream $stream): RawBodyNode
    {
        $token = $stream->consume();

        return new RawBodyNode($token->value, $token->line, $token->column);
    }

    /**
     * Skip an unexpected token to avoid infinite loops.
     */
    private function skipToken(TokenStream $stream): null
    {
        $stream->consume();

        return null;
    }

    /**
     * Normalize an output expression: trim and strip trailing semicolons.
     */
    private function normalizeExpression(string $expression): string
    {
        $expression = trim($expression);
        if ($expression !== '' && str_ends_with($expression, ';')) {
            return rtrim($expression, " \t\n\r\0\x0B;");
        }

        return $expression;
    }

    /**
     * Strip the configured raw directive attribute from an attribute list.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function stripRawAttribute(array $attributes): array
    {
        $rawName = $this->prefixHelper->buildName('raw');

        return array_values(array_filter(
            $attributes,
            static fn(AttributeNode $attr): bool => $attr->name !== $rawName,
        ));
    }
}
