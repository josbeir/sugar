<?php
declare(strict_types=1);

namespace Sugar\Core\Pass;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Context\AnalysisContext;
use Sugar\Core\Enum\OutputContext;

/**
 * Analyzes AST and assigns proper OutputContext to OutputNodes
 * based on their position in HTML structure
 */
final class ContextAnalysisPass
{
    /**
     * Analyze AST and update OutputNode contexts
     *
     * @param \Sugar\Core\Ast\DocumentNode $ast Document to analyze
     * @return \Sugar\Core\Ast\DocumentNode New document with updated contexts
     */
    public function analyze(DocumentNode $ast): DocumentNode
    {
        $context = new AnalysisContext();
        $inAttribute = false;

        $newChildren = [];

        foreach ($ast->children as $node) {
            if ($node instanceof TextNode) {
                // Parse HTML tags and update context
                [$context, $inAttribute] = $this->processTextNode($node, $context, $inAttribute);
                $newChildren[] = $node;
            } elseif ($node instanceof OutputNode) {
                // Update context for output nodes
                $newChildren[] = $this->updateOutputNode($node, $context, $inAttribute);
            } else {
                // RawPhpNode and others pass through unchanged
                $newChildren[] = $node;
            }
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Process text node to extract HTML tags and update context
     *
     * @param \Sugar\Core\Ast\TextNode $node Text node
     * @param \Sugar\Core\Context\AnalysisContext $context Current context
     * @param bool $inAttribute Whether currently in attribute
     * @return array{\Sugar\Core\Context\AnalysisContext, bool} Updated context and attribute flag
     */
    private function processTextNode(
        TextNode $node,
        AnalysisContext $context,
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
                        $context = $context->pop($tagName);
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
                            $context = $context->push($tagName);
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

        return [$context, $inAttribute];
    }

    /**
     * Update OutputNode with proper context
     *
     * @param \Sugar\Core\Ast\OutputNode $node Output node
     * @param \Sugar\Core\Context\AnalysisContext $context Current context
     * @param bool $inAttribute Whether in attribute
     * @return \Sugar\Core\Ast\OutputNode New output node with updated context
     */
    private function updateOutputNode(
        OutputNode $node,
        AnalysisContext $context,
        bool $inAttribute,
    ): OutputNode {
        // Don't modify raw output
        if (!$node->escape) {
            return $node;
        }

        // Determine context based on position
        $newContext = $inAttribute
            ? OutputContext::HTML_ATTRIBUTE
            : $context->determineContext();

        // Return new node with updated context
        return new OutputNode(
            expression: $node->expression,
            escape: $node->escape,
            context: $newContext,
            line: $node->line,
            column: $node->column,
        );
    }
}
