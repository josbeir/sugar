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
use Sugar\Parser\Helper\NodeFactory;
use Sugar\Parser\Helper\ParserState;
use Sugar\Parser\Helper\PipeParser;
use Sugar\Parser\Helper\TokenStream;

final readonly class Parser
{
    private SugarConfig $config;

    private DirectivePrefixHelper $prefixHelper;

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
        $this->nodeFactory = new NodeFactory();
        $this->htmlParser = new HtmlParser($this->config, $this->prefixHelper, $this->nodeFactory);
    }

    /**
     * Parse a Sugar template into an AST
     *
     * @param string $source Template source code
     * @return \Sugar\Ast\DocumentNode The parsed document
     */
    public function parse(string $source): DocumentNode
    {
        $tokens = Token::tokenize($source);
        $stream = new TokenStream($tokens);
        $state = new ParserState($stream, $source);
        $nodes = $this->parseTokens($state);

        return new DocumentNode($nodes);
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
