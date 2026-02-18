<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\RawPhp;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\PhpImportExtractor;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;

/**
 * Normalizes raw PHP blocks for code generation compatibility.
 *
 * Walks each {@see RawPhpNode} in the AST and extracts any leading `use`,
 * `use function`, or `use const` statements, converting them to typed
 * {@see \Sugar\Core\Ast\PhpImportNode} instances via
 * {@see PhpImportExtractor::extractImportNodes()}.
 *
 * The resulting import nodes are placed immediately before any remaining
 * executable code. The {@see \Sugar\Core\Compiler\CodeGen\CodeGenerator}
 * then collects all import nodes from the final composed document, deduplicates
 * them via {@see \Sugar\Core\Compiler\PhpImportRegistry}, and hoists them to
 * file scope above the render closure.
 */
final class PhpNormalizationPass implements AstPassInterface
{
    private readonly PhpImportExtractor $importExtractor;

    /**
     * Create PHP normalization pass.
     */
    public function __construct()
    {
        $this->importExtractor = new PhpImportExtractor();
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if (!($node instanceof RawPhpNode)) {
            return NodeAction::none();
        }

        $rawCode = trim($node->code);
        if ($rawCode === '' || !str_contains($rawCode, 'use')) {
            return NodeAction::none();
        }

        [$importNodes, $remainingCode] = $this->importExtractor->extractImportNodes($node);
        if ($importNodes === []) {
            return NodeAction::none();
        }

        if (trim($remainingCode) === '') {
            return NodeAction::replace($importNodes);
        }

        $replacement = new RawPhpNode(trim($remainingCode), $node->line, $node->column);
        $replacement->inheritTemplatePathFrom($node);

        return NodeAction::replace([...$importNodes, $replacement]);
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
