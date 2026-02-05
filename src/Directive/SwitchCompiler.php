<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for switch/case/default directives
 *
 * Transforms s:switch with s:case and s:default children into PHP switch statements.
 *
 * Example:
 * ```
 * <div s:switch="$user->role">
 *     <div s:case="'admin'">Admin Panel</div>
 *     <div s:case="'moderator'">Moderator Tools</div>
 *     <div s:default>User Dashboard</div>
 * </div>
 * ```
 *
 * Compiles to:
 * ```php
 * <?php switch ($user->role): ?>
 *     <?php case 'admin': ?>
 *         <div>Admin Panel</div>
 *         <?php break; ?>
 *     <?php case 'moderator': ?>
 *         <div>Moderator Tools</div>
 *         <?php break; ?>
 *     <?php default: ?>
 *         <div>User Dashboard</div>
 * <?php endswitch; ?>
 * ```
 */
readonly class SwitchCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // Extract case and default children
        $cases = [];
        $default = null;
        $hasValidCases = false;

        foreach ($node->children as $child) {
            // Check direct DirectiveNode children (for unit tests and manual AST construction)
            if ($child instanceof DirectiveNode) {
                if ($child->name === 'case') {
                    if (trim($child->expression) === '') {
                        throw $context->createException(
                            SyntaxException::class,
                            'Case directive requires a value expression',
                            $child->line,
                            $child->column,
                        );
                    }

                    $cases[] = $child;
                    $hasValidCases = true;
                } elseif ($child->name === 'default') {
                    if ($default instanceof DirectiveNode) {
                        throw $context->createException(
                            SyntaxException::class,
                            'Switch directive can only have one default case',
                            $child->line,
                            $child->column,
                        );
                    }

                    $default = $child;
                    $hasValidCases = true;
                }
            }

            // Check DirectiveNodes nested inside ElementNodes (from template syntax after extraction)
            if ($child instanceof ElementNode) {
                foreach ($child->children as $grandchild) {
                    if ($grandchild instanceof DirectiveNode) {
                        if ($grandchild->name === 'case') {
                            if (trim($grandchild->expression) === '') {
                                throw $context->createException(
                                    SyntaxException::class,
                                    'Case directive requires a value expression',
                                    $grandchild->line,
                                    $grandchild->column,
                                );
                            }

                            $cases[] = $grandchild;
                            $hasValidCases = true;
                        } elseif ($grandchild->name === 'default') {
                            if ($default instanceof DirectiveNode) {
                                throw $context->createException(
                                    SyntaxException::class,
                                    'Switch directive can only have one default case',
                                    $grandchild->line,
                                    $grandchild->column,
                                );
                            }

                            $default = $grandchild;
                            $hasValidCases = true;
                        }
                    }
                }
            }
        }

        if (!$hasValidCases) {
            throw $context->createException(
                SyntaxException::class,
                'Switch directive must contain at least one case or default',
                $node->line,
                $node->column,
            );
        }

        $parts = [];

        // Opening switch statement
        $parts[] = new RawPhpNode(
            'switch (' . $node->expression . '):',
            $node->line,
            $node->column,
        );

        // Compile each case
        foreach ($cases as $case) {
            $parts[] = new RawPhpNode(
                'case ' . $case->expression . ':',
                $case->line,
                $case->column,
            );

            // Case content
            array_push($parts, ...$case->children);

            // Auto-inject break after each case
            $parts[] = new RawPhpNode('break;', $case->line, $case->column);
        }

        // Compile default case if present
        if ($default instanceof DirectiveNode) {
            $parts[] = new RawPhpNode('default:', $default->line, $default->column);
            array_push($parts, ...$default->children);
            // No break needed after default (it's the last case)
        }

        // Closing switch statement
        $parts[] = new RawPhpNode('endswitch;', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
