<?php
declare(strict_types=1);

namespace Sugar\Parser;

use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Enum\OutputContext;
use Sugar\Parser\Helper\AttributeContinuation;
use Sugar\Parser\Helper\ClosingTagMarker;
use Sugar\Parser\Helper\HtmlParser;
use Sugar\Parser\Helper\HtmlScanHelper;
use Sugar\Parser\Helper\NodeFactory;
use Sugar\Parser\Helper\ParserState;
use Sugar\Parser\Helper\PipeParser;
use Sugar\Parser\Helper\TokenStream;
use Sugar\Runtime\HtmlTagHelper;

final readonly class Parser
{
    private SugarConfig $config;

    private DirectivePrefixHelper $prefixHelper;

    private HtmlScanHelper $htmlScanHelper;

    private HtmlParser $htmlParser;

    private NodeFactory $nodeFactory;

    /**
     * Constructor
     *
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(?SugarConfig $config = null)
    {
        $this->config = $config ?? new SugarConfig();
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
        $this->htmlScanHelper = new HtmlScanHelper();
        $this->nodeFactory = new NodeFactory();
        $this->htmlParser = new HtmlParser(
            $this->config,
            $this->prefixHelper,
            $this->nodeFactory,
            $this->htmlScanHelper,
        );
    }

    /**
     * Parse a Sugar template into an AST
     *
     * @param string $source Template source code
     * @return \Sugar\Ast\DocumentNode The parsed document
     */
    public function parse(string $source): DocumentNode
    {
        $tokens = $this->tokenizeSource($source);
        $stream = new TokenStream($tokens);
        $state = new ParserState($stream, $source);
        $nodes = $this->parseTokens($state);

        return new DocumentNode($nodes);
    }

    /**
     * Tokenize source, preserving raw regions as dedicated raw-body tokens.
     *
     * @return array<\Sugar\Parser\Token>
     */
    private function tokenizeSource(string $source): array
    {
        if (!$this->hasRawRegions($source)) {
            return Token::tokenize($source);
        }

        $tokens = [];
        $offset = 0;
        $rawAttribute = $this->prefixHelper->buildName('raw');
        $lineStarts = $this->htmlScanHelper->buildLineStarts($source);

        while (($region = $this->findNextRawRegion($source, $offset, $rawAttribute)) !== null) {
            $this->appendTokenizedChunk($tokens, $source, $offset, $region['openStart'], $lineStarts);
            $this->appendInlineHtmlToken($tokens, $source, $region['openStart'], $region['openEnd'], $lineStarts);

            $inner = substr($source, $region['innerStart'], $region['innerEnd'] - $region['innerStart']);
            $tokens[] = new Token(
                Token::T_RAW_BODY,
                $inner,
                $this->htmlScanHelper->findLineNumberFromStarts($lineStarts, $region['innerStart']),
                $region['innerStart'],
            );

            $this->appendInlineHtmlToken($tokens, $source, $region['closeStart'], $region['closeEnd'], $lineStarts);
            $offset = $region['closeEnd'];
        }

        $this->appendTokenizedChunk($tokens, $source, $offset, strlen($source), $lineStarts);

        return $tokens;
    }

    /**
     * Determine whether the source may contain raw directive regions.
     */
    private function hasRawRegions(string $source): bool
    {
        return str_contains($source, $this->prefixHelper->buildName('raw'));
    }

    /**
     * Append tokenized source chunk with corrected absolute line/position metadata.
     *
     * @param array<\Sugar\Parser\Token> $tokens
     * @param array<int, int> $lineStarts
     */
    private function appendTokenizedChunk(array &$tokens, string $source, int $start, int $end, array $lineStarts): void
    {
        if ($end <= $start) {
            return;
        }

        $chunk = substr($source, $start, $end - $start);
        if ($chunk === '') {
            return;
        }

        if (!str_contains($chunk, '<?')) {
            $tokens[] = new Token(
                T_INLINE_HTML,
                $chunk,
                $this->htmlScanHelper->findLineNumberFromStarts($lineStarts, $start),
                $start,
            );

            return;
        }

        $baseLine = $this->htmlScanHelper->findLineNumberFromStarts($lineStarts, $start);
        foreach (Token::tokenize($chunk) as $token) {
            $tokens[] = new Token(
                $token->id,
                $token->text,
                $baseLine + $token->line - 1,
                $start + $token->pos,
            );
        }
    }

    /**
     * Append an HTML boundary token directly without PHP tokenization overhead.
     *
     * @param array<\Sugar\Parser\Token> $tokens
     * @param array<int, int> $lineStarts
     */
    private function appendInlineHtmlToken(
        array &$tokens,
        string $source,
        int $start,
        int $end,
        array $lineStarts,
    ): void {
        if ($end <= $start) {
            return;
        }

        $content = substr($source, $start, $end - $start);
        if ($content === '') {
            return;
        }

        $tokens[] = new Token(
            T_INLINE_HTML,
            $content,
            $this->htmlScanHelper->findLineNumberFromStarts($lineStarts, $start),
            $start,
        );
    }

    /**
     * @return array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int}|null
     */
    private function findNextRawRegion(string $source, int $offset, string $rawAttribute): ?array
    {
        while (($tagStart = strpos($source, '<', $offset)) !== false) {
            $tag = $this->extractTagAt($source, $tagStart);
            if ($tag === null || $tag['type'] !== 'open') {
                $offset = $tagStart + 1;
                continue;
            }

            $offset = $tag['end'];

            if ($tag['selfClosing']) {
                continue;
            }

            if (!$this->hasRawAttribute($tag['raw'], $rawAttribute)) {
                continue;
            }

            $closeStart = $this->findMatchingClose($source, $tag['name'], $tag['end']);
            if ($closeStart === null) {
                continue;
            }

            $closeTag = $this->extractTagAt($source, $closeStart);
            if ($closeTag === null) {
                continue;
            }

            if ($closeTag['type'] !== 'close') {
                continue;
            }

            return [
                'openStart' => $tag['start'],
                'openEnd' => $tag['end'],
                'innerStart' => $tag['end'],
                'innerEnd' => $closeStart,
                'closeStart' => $closeStart,
                'closeEnd' => $closeTag['end'],
            ];
        }

        return null;
    }

    /**
     * @return array{type: 'open'|'close', name: string, start: int, end: int, selfClosing: bool, raw: string}|null
     */
    private function extractTagAt(string $source, int $start): ?array
    {
        if (!isset($source[$start]) || $source[$start] !== '<') {
            return null;
        }

        $next = $source[$start + 1] ?? null;
        if (in_array($next, [null, '!', '?'], true)) {
            return null;
        }

        if ($next === '/') {
            $nameStart = $start + 2;
            $nameEnd = $this->htmlScanHelper->readTagNameEnd($source, $nameStart);
            if ($nameEnd === $nameStart) {
                return null;
            }

            $end = $this->htmlScanHelper->findTagEnd($source, $nameEnd);
            if ($end === null) {
                return null;
            }

            return [
                'type' => 'close',
                'name' => substr($source, $nameStart, $nameEnd - $nameStart),
                'start' => $start,
                'end' => $end,
                'selfClosing' => false,
                'raw' => substr($source, $start, $end - $start),
            ];
        }

        if (!ctype_alpha($next)) {
            return null;
        }

        $nameStart = $start + 1;
        $nameEnd = $this->htmlScanHelper->readTagNameEnd($source, $nameStart);
        if ($nameEnd === $nameStart) {
            return null;
        }

        $end = $this->htmlScanHelper->findTagEnd($source, $nameEnd);
        if ($end === null) {
            return null;
        }

        $name = substr($source, $nameStart, $nameEnd - $nameStart);
        $rawTag = substr($source, $start, $end - $start);
        $selfClosing = str_ends_with(rtrim($rawTag), '/>')
            || HtmlTagHelper::isSelfClosing($name, $this->config->selfClosingTags);

        return [
            'type' => 'open',
            'name' => $name,
            'start' => $start,
            'end' => $end,
            'selfClosing' => $selfClosing,
            'raw' => $rawTag,
        ];
    }

    /**
     * Find matching closing tag position for an opening tag offset.
     */
    private function findMatchingClose(string $source, string $tagName, int $offset): ?int
    {
        $depth = 1;

        while (($tagStart = strpos($source, '<', $offset)) !== false) {
            $tag = $this->extractTagAt($source, $tagStart);
            if ($tag === null) {
                $offset = $tagStart + 1;
                continue;
            }

            $offset = $tag['end'];

            if (strcasecmp($tag['name'], $tagName) !== 0) {
                continue;
            }

            if ($tag['type'] === 'close') {
                $depth--;
                if ($depth === 0) {
                    return $tag['start'];
                }

                continue;
            }

            if (!$tag['selfClosing']) {
                $depth++;
            }
        }

        return null;
    }

    /**
     * Check whether an opening tag contains the configured raw directive attribute.
     */
    private function hasRawAttribute(string $tag, string $rawAttribute): bool
    {
        $pattern =
            '/(?:\s|^)' .
            preg_quote($rawAttribute, '/') .
            '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/';

        return preg_match($pattern, $tag) === 1;
    }

    /**
     * Parse tokens into AST nodes
     *
     * @param \Sugar\Parser\Helper\ParserState $state Parser state
     * @return array<\Sugar\Ast\Node> AST nodes
     */
    private function parseTokens(ParserState $state): array
    {
        $stream = $state->stream;

        while (!$stream->isEnd()) {
            $token = $stream->next();
            if (!$token instanceof Token) {
                break;
            }

            if ($token->isOutput()) {
                $column = $state->columnFromOffset($token->pos);
                $expression = $state->consumeExpression();
                $expression = $state->normalizeOutputExpression($expression);

                // Parse pipe syntax if present
                $pipes = PipeParser::parse($expression);
                $finalExpression = $pipes['expression'];
                $pipeChain = $pipes['pipes'];
                $shouldEscape = !$pipes['raw'];
                if ($pipes['raw']) {
                    $outputContext = OutputContext::RAW;
                } elseif ($pipes['json']) {
                    $outputContext = $state->hasPendingAttribute()
                        ? OutputContext::JSON_ATTRIBUTE
                        : OutputContext::JSON;
                } else {
                    $outputContext = $state->hasPendingAttribute()
                        ? OutputContext::HTML_ATTRIBUTE
                        : OutputContext::HTML;
                }

                $outputNode = $this->nodeFactory->output(
                    expression: $finalExpression,
                    escape: $shouldEscape,
                    context: $outputContext,
                    line: $token->line,
                    column: $column,
                    pipes: $pipeChain,
                );

                if ($state->hasPendingAttribute()) {
                    $pendingAttribute = $state->pendingAttribute();
                    if ($pendingAttribute === null) {
                        continue;
                    }

                    $element = $pendingAttribute['element'];
                    $attrIndex = $pendingAttribute['attrIndex'];
                    AttributeContinuation::appendAttributeValuePart(
                        $element->attributes[$attrIndex],
                        $outputNode,
                    );
                    continue;
                }

                $state->addNode($outputNode);
                continue;
            }

            if ($token->isOpenTag()) {
                $column = $state->columnFromOffset($token->pos);
                $code = $state->consumePhpBlock();
                $state->addNode($this->nodeFactory->rawPhp($code, $token->line, $column));
                continue;
            }

            if ($token->isRawBody()) {
                $column = $state->columnFromOffset($token->pos);
                $state->addNode($this->nodeFactory->rawBody($token->content(), $token->line, $column));
                continue;
            }

            if ($token->canIgnore()) {
                continue;
            }

            if ($token->isHtml()) {
                $column = $state->columnFromOffset($token->pos);
                $html = $token->content();
                if ($state->hasPendingAttribute()) {
                    $pendingAttribute = $state->pendingAttribute();
                    if ($pendingAttribute === null) {
                        $state->setPendingAttribute(null);
                        continue;
                    }

                    [$html, $pendingAttribute] = AttributeContinuation::consumeAttributeContinuation(
                        $html,
                        $pendingAttribute,
                        $this->nodeFactory,
                    );
                    $state->setPendingAttribute($pendingAttribute);
                    if ($html === '') {
                        continue;
                    }
                }

                if (str_contains($html, '<') || str_contains($html, '>')) {
                    $htmlNodes = $this->htmlParser->parse($html, $token->line, $column);
                    $this->stripRawDirectiveAttributes($htmlNodes);
                    $state->addNodes($htmlNodes);
                    if (!$state->hasPendingAttribute()) {
                        $state->setPendingAttribute(
                            AttributeContinuation::detectOpenAttribute($html, $htmlNodes),
                        );
                    }
                } else {
                    $state->addNode($this->nodeFactory->text($html, $token->line, $column));
                }

                continue;
            }
        }

        return $this->buildTree($state->nodes());
    }

    /**
     * @param array<\Sugar\Ast\Node|\Sugar\Parser\Helper\ClosingTagMarker> $nodes
     */
    private function stripRawDirectiveAttributes(array $nodes): void
    {
        $rawAttributeName = $this->prefixHelper->buildName('raw');

        foreach ($nodes as $node) {
            if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof ComponentNode) {
                $remainingAttributes = [];
                foreach ($node->attributes as $attribute) {
                    if ($attribute->name === $rawAttributeName) {
                        continue;
                    }

                    $remainingAttributes[] = $attribute;
                }

                $node->attributes = $remainingAttributes;
            }
        }
    }

    /**
     * Build tree structure from flat node list
     *
     * @param array<\Sugar\Ast\Node|\Sugar\Parser\Helper\ClosingTagMarker> $flatNodes Flat list
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
}
