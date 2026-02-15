<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

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
readonly class SwitchDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
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
                        throw $context->createSyntaxExceptionForNode(
                            'Case directive requires a value expression',
                            $child,
                        );
                    }

                    $cases[] = $child;
                    $hasValidCases = true;
                } elseif ($child->name === 'default') {
                    if ($default instanceof DirectiveNode) {
                        throw $context->createSyntaxExceptionForNode(
                            'Switch directive can only have one default case',
                            $child,
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
                                throw $context->createSyntaxExceptionForNode(
                                    'Case directive requires a value expression',
                                    $grandchild,
                                );
                            }

                            $cases[] = $grandchild;
                            $hasValidCases = true;
                        } elseif ($grandchild->name === 'default') {
                            if ($default instanceof DirectiveNode) {
                                throw $context->createSyntaxExceptionForNode(
                                    'Switch directive can only have one default case',
                                    $grandchild,
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
            throw $context->createSyntaxExceptionForNode(
                'Switch directive must contain at least one case or default',
                $node,
            );
        }

        $parts = [];

        // Opening switch statement
        $switchNode = new RawPhpNode(
            'switch (' . $node->expression . '):',
            $node->line,
            $node->column,
        );
        $switchNode->inheritTemplatePathFrom($node);
        $parts[] = $switchNode;

        // Compile each case
        foreach ($cases as $case) {
            $caseNode = new RawPhpNode(
                'case ' . $case->expression . ':',
                $case->line,
                $case->column,
            );
            $caseNode->inheritTemplatePathFrom($case);
            $parts[] = $caseNode;

            // Case content
            array_push($parts, ...$case->children);

            // Auto-inject break after each case
            $breakNode = new RawPhpNode('break;', $case->line, $case->column);
            $breakNode->inheritTemplatePathFrom($case);
            $parts[] = $breakNode;
        }

        // Compile default case if present
        if ($default instanceof DirectiveNode) {
            $defaultNode = new RawPhpNode('default:', $default->line, $default->column);
            $defaultNode->inheritTemplatePathFrom($default);
            $parts[] = $defaultNode;
            array_push($parts, ...$default->children);
            // No break needed after default (it's the last case)
        }

        // Closing switch statement
        $endNode = new RawPhpNode('endswitch;', $node->line, $node->column);
        $endNode->inheritTemplatePathFrom($node);
        $parts[] = $endNode;

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
