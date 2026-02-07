<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Directive\Trait\WrapperModeTrait;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;
use Sugar\Util\Hash;

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
readonly class TimesCompiler implements DirectiveCompilerInterface
{
    use WrapperModeTrait;

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
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
     * @param \Sugar\Ast\DirectiveNode $node
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
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
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
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array{0: string, 1: string}
     */
    private function parseTimesExpression(Node $node, CompilationContext $context): array
    {
        $rawExpression = trim($node->expression);

        if ($rawExpression === '') {
            $message = 'times directive requires a count expression.';

            throw $context->createException(
                SyntaxException::class,
                $message,
                $node->line,
                $node->column,
            );
        }

        $parts = preg_split('/\s+as\s+/i', $rawExpression, 2);
        $countExpression = trim($parts[0] ?? '');

        if ($countExpression === '') {
            $message = 'times directive requires a count expression.';

            throw $context->createException(
                SyntaxException::class,
                $message,
                $node->line,
                $node->column,
            );
        }

        if (isset($parts[1])) {
            $indexVar = trim($parts[1]);

            if (!preg_match('/^\$[a-zA-Z_]\w*$/', $indexVar)) {
                $message = 'times directive index must be a valid variable name.';

                throw $context->createException(
                    SyntaxException::class,
                    $message,
                    $node->line,
                    $node->column,
                );
            }

            return [$countExpression, $indexVar];
        }

        $indexVar = '$__times_' . Hash::short($rawExpression . $node->line . $node->column);

        return [$countExpression, $indexVar];
    }

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     */
    private function buildForOpening(Node $node, string $countExpression, string $indexVar): RawPhpNode
    {
        return new RawPhpNode(
            sprintf('for (%s = 0; %s < (%s); %s++):', $indexVar, $indexVar, $countExpression, $indexVar),
            $node->line,
            $node->column,
        );
    }

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     */
    private function buildForClosing(Node $node): RawPhpNode
    {
        return new RawPhpNode('endfor;', $node->line, $node->column);
    }
}
