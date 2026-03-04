<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;

/**
 * Compiler for switch/case/default directives
 *
 * Handles all three directive names (switch, case, default) registered in the
 * directive registry. The pipeline walks depth-first and compiles case/default
 * children before the parent switch, so each name is compiled independently:
 *
 * - **case**: emits `case <expr>:` + children + `break;`
 * - **default**: emits `default:` + children
 * - **switch**: wraps all (already-compiled) children in `switch()/endswitch`
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
     * Compile a switch, case, or default directive node
     *
     * Dispatches to the appropriate compilation method based on the directive name.
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        assert($node instanceof DirectiveNode);

        return match ($node->name) {
            'case' => $this->compileCase($node, $context),
            'default' => $this->compileDefault($node, $context),
            default => $this->compileSwitch($node, $context),
        };
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * Compile a case directive into a PHP case statement with auto-break
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The case directive node
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @return array<\Sugar\Core\Ast\Node>
     */
    protected function compileCase(DirectiveNode $node, CompilationContext $context): array
    {
        if (trim($node->expression) === '') {
            throw $context->createSyntaxExceptionForNode(
                'Case directive requires a value expression',
                $node,
            );
        }

        $caseNode = new RawPhpNode(
            'case ' . $node->expression . ':',
            $node->line,
            $node->column,
        );
        $caseNode->inheritTemplatePathFrom($node);

        $breakNode = new RawPhpNode('break;', $node->line, $node->column);
        $breakNode->inheritTemplatePathFrom($node);

        return [$caseNode, ...$node->children, $breakNode];
    }

    /**
     * Compile a default directive into a PHP default statement
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The default directive node
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @return array<\Sugar\Core\Ast\Node>
     */
    protected function compileDefault(DirectiveNode $node, CompilationContext $context): array
    {
        $defaultNode = new RawPhpNode('default:', $node->line, $node->column);
        $defaultNode->inheritTemplatePathFrom($node);

        return [$defaultNode, ...$node->children];
    }

    /**
     * Compile the switch directive wrapper
     *
     * Collects effective children (unwrapping wrapper ElementNodes) and validates
     * that at least one case or default is present. Children may be DirectiveNodes
     * (from direct AST construction) or already-compiled RawPhpNodes (from the
     * pipeline's bottom-up traversal).
     *
     * A state machine tracks whether we are inside a case body so that only
     * case/default labels and their body content are emitted. Non-case content
     * (e.g. elements without s:case/s:default) is excluded from the switch output
     * to avoid PHP syntax errors in the alternative switch/endswitch syntax.
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The switch directive node
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @return array<\Sugar\Core\Ast\Node>
     */
    protected function compileSwitch(DirectiveNode $node, CompilationContext $context): array
    {
        $effectiveChildren = $this->collectEffectiveChildren($node->children);

        $parts = [];
        $hasValidCases = false;
        $defaultCount = 0;
        $insideCaseBody = false;

        foreach ($effectiveChildren as $child) {
            // DirectiveNode children (from direct calls / unit tests without pipeline)
            if ($child instanceof DirectiveNode && $child->name === 'case') {
                array_push($parts, ...$this->compileCase($child, $context));
                $hasValidCases = true;
                $insideCaseBody = false; // compileCase() emits break; at end

                continue;
            }

            if ($child instanceof DirectiveNode && $child->name === 'default') {
                $defaultCount++;
                if ($defaultCount > 1) {
                    throw $context->createSyntaxExceptionForNode(
                        'Switch directive can only have one default case',
                        $child,
                    );
                }

                array_push($parts, ...$this->compileDefault($child, $context));
                $hasValidCases = true;
                $insideCaseBody = true; // default has no break, content follows until endswitch

                continue;
            }

            // Already-compiled RawPhpNodes (from pipeline bottom-up compilation)
            if ($child instanceof RawPhpNode) {
                if (str_starts_with($child->code, 'case ') || $child->code === 'default:') {
                    if ($child->code === 'default:') {
                        $defaultCount++;
                        if ($defaultCount > 1) {
                            throw $context->createSyntaxExceptionForNode(
                                'Switch directive can only have one default case',
                                $child,
                            );
                        }
                    }

                    $hasValidCases = true;
                    $insideCaseBody = true;
                    $parts[] = $child;

                    continue;
                }

                if ($child->code === 'break;') {
                    $parts[] = $child;
                    $insideCaseBody = false;

                    continue;
                }
            }

            // Only include content nodes when inside a case/default body
            if ($insideCaseBody) {
                $parts[] = $child;
            }
        }

        if (!$hasValidCases) {
            throw $context->createSyntaxExceptionForNode(
                'Switch directive must contain at least one case or default',
                $node,
            );
        }

        $switchNode = new RawPhpNode(
            'switch (' . $node->expression . '):',
            $node->line,
            $node->column,
        );
        $switchNode->inheritTemplatePathFrom($node);

        $endNode = new RawPhpNode('endswitch;', $node->line, $node->column);
        $endNode->inheritTemplatePathFrom($node);

        return [$switchNode, ...$parts, $endNode];
    }

    /**
     * Collect effective children by unwrapping the single wrapper ElementNode
     *
     * After directive extraction, the original host element (e.g. `<div s:switch>`)
     * becomes a single ElementNode child of the switch DirectiveNode. This method
     * unwraps that one level so that the case/default DirectiveNodes (or their
     * already-compiled RawPhpNode equivalents) can be found directly.
     *
     * Only one level of ElementNode wrapping is unwrapped, which is exactly what
     * the pipeline produces. Deeper ElementNodes (e.g. the host elements of
     * individual s:case or s:default children) are intentionally left in place
     * as content nodes within each case body.
     *
     * @param array<\Sugar\Core\Ast\Node> $children Direct children of the switch DirectiveNode
     * @return array<\Sugar\Core\Ast\Node> Children with the single wrapper ElementNode unwrapped
     */
    protected function collectEffectiveChildren(array $children): array
    {
        $result = [];

        foreach ($children as $child) {
            if ($child instanceof ElementNode) {
                array_push($result, ...$child->children);
            } else {
                $result[] = $child;
            }
        }

        return $result;
    }
}
