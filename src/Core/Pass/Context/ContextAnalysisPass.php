<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Context;

use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Escape\Enum\OutputContext;

/**
 * Analyzes the AST and assigns OutputContext to OutputNodes.
 *
 * Uses element nesting for script/style context and inspects
 * attribute values to mark HTML attribute output.
 */
final class ContextAnalysisPass implements AstPassInterface
{
    private AnalysisContext $analysisContext;

    /**
     * @var array<int, \Sugar\Core\Pass\Context\AnalysisContext>
     */
    private array $contextStack = [];

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof DocumentNode) {
            $this->analysisContext = new AnalysisContext();
            $this->contextStack = [];

            return NodeAction::none();
        }

        if ($node instanceof ElementNode) {
            $this->updateAttributeContexts($node, $this->analysisContext);
            $this->contextStack[] = $this->analysisContext;
            $this->analysisContext = $this->analysisContext->push($node->tag);

            return NodeAction::none();
        }

        if ($node instanceof TextNode || $node instanceof RawBodyNode) {
            return NodeAction::none();
        }

        if ($node instanceof OutputNode) {
            return NodeAction::replace($this->updateOutputNode($node, $this->analysisContext, false));
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
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
     * Update OutputNode contexts for element attribute values.
     */
    private function updateAttributeContexts(ElementNode $node, AnalysisContext $analysisContext): void
    {
        foreach ($node->attributes as $attribute) {
            if ($attribute->value->isOutput()) {
                $output = $attribute->value->output;
                if ($output instanceof OutputNode) {
                    $attribute->value = AttributeValue::output($this->updateOutputNode(
                        $output,
                        $analysisContext,
                        true,
                    ));
                }

                continue;
            }

            $parts = $attribute->value->toParts();
            if ($parts === null) {
                continue;
            }

            $hasOutput = false;
            foreach ($parts as $index => $part) {
                if (!$part instanceof OutputNode) {
                    continue;
                }

                $hasOutput = true;
                $parts[$index] = $this->updateOutputNode(
                    $part,
                    $analysisContext,
                    true,
                );
            }

            if ($hasOutput) {
                $attribute->value = AttributeValue::parts($parts);
            }
        }
    }

    /**
     * Update OutputNode with proper context
     *
     * @param \Sugar\Core\Ast\OutputNode $node Output node
     * @param \Sugar\Core\Pass\Context\AnalysisContext $analysisContext Current context
     * @param bool $inAttribute Whether in attribute
     * @return \Sugar\Core\Ast\OutputNode New output node with updated context
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

        if ($node->context === OutputContext::JSON || $node->context === OutputContext::JSON_ATTRIBUTE) {
            return $node;
        }

        // Determine context based on position
        $newContext = $inAttribute
            ? OutputContext::HTML_ATTRIBUTE
            : $analysisContext->determineContext();

        $updatedNode = new OutputNode(
            expression: $node->expression,
            escape: $node->escape,
            context: $newContext,
            line: $node->line,
            column: $node->column,
            pipes: $node->pipes,
        );

        $updatedNode->inheritTemplatePathFrom($node);

        return $updatedNode;
    }
}
