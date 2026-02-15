<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Trait\WrapperModeTrait;
use Sugar\Core\Enum\DirectiveType;
use Sugar\Core\Util\Hash;

/**
 * Compiler for times directive
 *
 * Transforms s:times directives into PHP for loops.
 *
 * Example:
 * ```
 * <div s:times="5">Item</div>
 * <div s:times="5 as $i">Item <?= $i ?></div>
 * ```
 */
readonly class TimesDirective implements DirectiveInterface
{
    use WrapperModeTrait;

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        [$countExpression, $indexVar] = $this->parseTimesExpression($node, $context);

        if ($this->shouldUseWrapperMode($node)) {
            return $this->compileWithWrapper($node, $this->getWrapperElement($node), $countExpression, $indexVar);
        }

        return $this->compileWithoutWrapper($node, $countExpression, $indexVar);
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * Compile with wrapper pattern - element is container, children repeat inside.
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function compileWithWrapper(
        Node $node,
        ElementNode $wrapper,
        string $countExpression,
        string $indexVar,
    ): array {
        return [NodeCloner::withChildren($wrapper, [
            $this->buildForOpening($node, $countExpression, $indexVar),
            ...$wrapper->children,
            $this->buildForClosing($node),
        ])];
    }

    /**
     * Compile without wrapper - repeat the directive element itself.
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function compileWithoutWrapper(Node $node, string $countExpression, string $indexVar): array
    {
        return [
            $this->buildForOpening($node, $countExpression, $indexVar),
            ...$node->children,
            $this->buildForClosing($node),
        ];
    }

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array{0: string, 1: string}
     */
    private function parseTimesExpression(Node $node, CompilationContext $context): array
    {
        $rawExpression = trim($node->expression);

        if ($rawExpression === '') {
            $message = 'times directive requires a count expression.';

            throw $context->createSyntaxExceptionForNode(
                $message,
                $node,
            );
        }

        $parts = preg_split('/\s+as\s+/i', $rawExpression, 2);
        $countExpression = trim($parts[0] ?? '');

        if ($countExpression === '') {
            $message = 'times directive requires a count expression.';

            throw $context->createSyntaxExceptionForNode(
                $message,
                $node,
            );
        }

        if (isset($parts[1])) {
            $indexVar = trim($parts[1]);

            if (!preg_match('/^\$[a-zA-Z_]\w*$/', $indexVar)) {
                $message = 'times directive index must be a valid variable name.';

                throw $context->createSyntaxExceptionForNode(
                    $message,
                    $node,
                );
            }

            return [$countExpression, $indexVar];
        }

        $indexVar = '$__times_' . Hash::short($rawExpression . $node->line . $node->column);

        return [$countExpression, $indexVar];
    }

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     */
    private function buildForOpening(Node $node, string $countExpression, string $indexVar): RawPhpNode
    {
        $rawNode = new RawPhpNode(
            sprintf('for (%s = 0; %s < (%s); %s++):', $indexVar, $indexVar, $countExpression, $indexVar),
            $node->line,
            $node->column,
        );

        $rawNode->inheritTemplatePathFrom($node);

        return $rawNode;
    }

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     */
    private function buildForClosing(Node $node): RawPhpNode
    {
        $rawNode = new RawPhpNode('endfor;', $node->line, $node->column);
        $rawNode->inheritTemplatePathFrom($node);

        return $rawNode;
    }
}
