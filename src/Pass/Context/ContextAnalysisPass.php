<?php
declare(strict_types=1);

namespace Sugar\Pass\Context;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Context\AnalysisContext;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Middleware\AstMiddlewarePassInterface;
use Sugar\Pass\Middleware\NodeAction;
use Sugar\Pass\Middleware\WalkContext;

/**
 * Analyzes AST and assigns proper OutputContext to OutputNodes
 * based on their position in HTML structure
 */
final class ContextAnalysisPass implements AstMiddlewarePassInterface
{
    private AnalysisContext $analysisContext;

    /**
     * @var array<int, \Sugar\Context\AnalysisContext>
     */
    private array $contextStack = [];

    private bool $inAttribute = false;

    /**
     * @inheritDoc
     */
    public function before(Node $node, WalkContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            $this->analysisContext = new AnalysisContext();
            $this->contextStack = [];
            $this->inAttribute = false;

            return NodeAction::none();
        }

        if ($node instanceof ElementNode) {
            $this->contextStack[] = $this->analysisContext;
            $this->analysisContext = $this->analysisContext->push($node->tag);

            return NodeAction::none();
        }

        if ($node instanceof TextNode) {
            [$this->analysisContext, $this->inAttribute] = $this->processTextNode(
                $node,
                $this->analysisContext,
                $this->inAttribute,
            );

            return NodeAction::none();
        }

        if ($node instanceof OutputNode) {
            return NodeAction::replace($this->updateOutputNode($node, $this->analysisContext, $this->inAttribute));
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, WalkContext $context): NodeAction
    {
        if ($node instanceof ElementNode) {
            $previous = array_pop($this->contextStack);
            if ($previous instanceof AnalysisContext) {
                $this->analysisContext = $previous;
            }
        }

        return NodeAction::none();
    }

    /**
     * Process text node to extract HTML tags and update context
     *
     * @param \Sugar\Ast\TextNode $node Text node
     * @param \Sugar\Context\AnalysisContext $analysisContext Current context
     * @param bool $inAttribute Whether currently in attribute
     * @return array{\Sugar\Context\AnalysisContext, bool} Updated context and attribute flag
     */
    private function processTextNode(
        TextNode $node,
        AnalysisContext $analysisContext,
        bool $inAttribute,
    ): array {
        $text = $node->content;

        // Simple state machine: track if we end with an unclosed attribute
        $inTag = false;
        $quoteCount = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $next = $text[$i + 1] ?? '';

            if ($char === '<') {
                $inTag = true;
                $quoteCount = 0;

                // Check if closing tag
                if ($next === '/') {
                    // Extract tag name
                    if (preg_match('/<\/([a-zA-Z][a-zA-Z0-9-]*)\s*>/i', substr($text, $i), $matches)) {
                        $tagName = strtolower($matches[1]);
                        $analysisContext = $analysisContext->pop($tagName);
                    }
                } elseif (preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)/i', substr($text, $i), $matches)) {
                    // Opening tag - extract tag name
                    $tagName = strtolower($matches[1]);
                    // Check if self-closing by looking ahead for />
                    $remaining = substr($text, $i);
                    $closePos = strpos($remaining, '>');
                    if ($closePos !== false) {
                        $tagPart = substr($remaining, 0, $closePos);
                        if (!str_ends_with(trim($tagPart), '/')) {
                            $analysisContext = $analysisContext->push($tagName);
                        }
                    }
                }
            } elseif ($char === '>') {
                $inTag = false;
                $quoteCount = 0;
                $inAttribute = false;
            } elseif ($inTag && $char === '"') {
                $quoteCount++;
                // If we have odd number of quotes, we're in an attribute
                $inAttribute = ($quoteCount % 2 === 1);
            } elseif ($inAttribute && $char === '"') {
                $inAttribute = false;
            }
        }

        // Check if text ends inside an attribute (ends with =" but no closing quote)
        if (str_ends_with($text, '="')) {
            $inAttribute = true;
        } elseif (preg_match('/="[^"]*$/i', $text)) {
            // Ends with =" followed by non-quote characters
            $inAttribute = true;
        }

        return [$analysisContext, $inAttribute];
    }

    /**
     * Update OutputNode with proper context
     *
     * @param \Sugar\Ast\OutputNode $node Output node
     * @param \Sugar\Context\AnalysisContext $analysisContext Current context
     * @param bool $inAttribute Whether in attribute
     * @return \Sugar\Ast\OutputNode New output node with updated context
     */
    private function updateOutputNode(
        OutputNode $node,
        AnalysisContext $analysisContext,
        bool $inAttribute,
    ): OutputNode {
        // Don't modify raw output - return as-is
        if (!$node->escape) {
            return $node;
        }

        // Determine context based on position
        $newContext = $inAttribute
            ? OutputContext::HTML_ATTRIBUTE
            : $analysisContext->determineContext();

        return new OutputNode(
            expression: $node->expression,
            escape: $node->escape,
            context: $newContext,
            line: $node->line,
            column: $node->column,
            pipes: $node->pipes,
        );
    }
}
