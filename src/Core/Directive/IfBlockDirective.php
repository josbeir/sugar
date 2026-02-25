<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementClaimingDirectiveInterface;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Core\Runtime\TemplateRenderer;

/**
 * Compiler for ifblock directive.
 *
 * Conditionally renders content only when a child template has defined
 * a block override for the provided block name.
 */
readonly class IfBlockDirective implements DirectiveInterface, ElementClaimingDirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [];

        $condition = RuntimeEnvironment::class
            . '::requireService(' . TemplateRenderer::class . '::class)'
            . '->hasDefinedBlock(' . $this->normalizeExpression($node->expression) . ')';

        $parts[] = new RawPhpNode(
            'if (' . $condition . '):',
            $node->line,
            $node->column,
        );

        array_push($parts, ...$node->children);

        $parts[] = new RawPhpNode('endif;', $node->line, $node->column);

        return $parts;
    }

    /**
     * Normalize directive expression to a valid block-name expression.
     */
    private function normalizeExpression(string $expression): string
    {
        $trimmed = trim($expression);
        if ($trimmed === '') {
            return "''";
        }

        if (
            str_starts_with($trimmed, "'")
            || str_starts_with($trimmed, '"')
            || str_starts_with($trimmed, '$')
            || str_starts_with($trimmed, '(')
            || str_starts_with($trimmed, 'array(')
            || str_starts_with($trimmed, '[')
        ) {
            return $trimmed;
        }

        if (preg_match('/^[a-zA-Z0-9_.:-]+$/', $trimmed) === 1) {
            return var_export($trimmed, true);
        }

        return $trimmed;
    }

    /**
     * The block name is supplied via the `name` attribute:
     * <s-ifblock name="sidebar">...</s-ifblock>
     */
    public function getElementExpressionAttribute(): string
    {
        return 'name';
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
