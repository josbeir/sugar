<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Element;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Interface\ElementClaimingDirectiveInterface;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistryInterface;

/**
 * Routes ComponentNodes produced by the parser for element-claiming directives.
 *
 * Any directive that implements ElementClaimingDirectiveInterface can be used either as
 * a regular s:NAME attribute or as a dedicated <s-NAME> element. This pass runs at
 * priority ELEMENT_ROUTING (15) — before DirectiveExtractionPass (20) — and converts:
 *
 * ```html
 * <s-youtube src="$videoId" s:if="$show">...</s-youtube>
 * ```
 *
 * Into a FragmentNode that DirectiveExtractionPass can process normally:
 *
 * ```
 * FragmentNode(attributes=[s:youtube="$videoId", s:if="$show"])
 *   [...original children...]
 * ```
 *
 * DirectiveExtractionPass (priority 20) then applies standard nesting rules — control
 * flow directives wrap the outer DirectiveNode, OUTPUT directives wrap the inner. This
 * means every s:* attribute on the element tag behaves identically to writing those
 * same attributes on an <s-template> wrapper.
 *
 * This pass throws SyntaxException for regular HTML attributes (e.g. class="foo") on
 * element-claiming directives, because those attributes would be silently discarded and
 * most likely indicate a programming mistake.
 *
 * This pass is a no-op for ComponentNodes whose name matches no registered directive,
 * or whose directive does not implement ElementClaimingDirectiveInterface.
 */
final class ElementRoutingPass implements AstPassInterface
{
    private readonly DirectivePrefixHelper $prefixHelper;

    /**
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry used to look up element-claiming directives
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration used to derive the directive attribute prefix
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if (!$node instanceof ComponentNode) {
            return NodeAction::none();
        }

        if (!$this->registry->has($node->name)) {
            return NodeAction::none();
        }

        $directive = $this->registry->get($node->name);

        if (!$directive instanceof ElementClaimingDirectiveInterface) {
            return NodeAction::none();
        }

        $expressionAttrName = $directive->getElementExpressionAttribute();
        $expression = '';
        $directiveAttrs = [];

        foreach ($node->attributes as $attr) {
            // Expression attribute (e.g. src="$videoId") becomes the directive expression
            if ($expressionAttrName !== null && $attr->name === $expressionAttrName) {
                if ($attr->value->isBoolean()) {
                    $expression = 'true';
                } elseif ($attr->value->isStatic()) {
                    $expression = $attr->value->static ?? '';
                } else {
                    throw new SyntaxException(
                        sprintf(
                            'The "%s" expression attribute on a custom element directive '
                            . 'must be a static PHP expression, not a dynamic output expression.',
                            $expressionAttrName,
                        ),
                    );
                }

                continue;
            }

            // Only s:* directive attributes are allowed alongside the expression attribute.
            // Regular HTML attributes (class="...", id="...") are not meaningful here and
            // would be silently dropped, so we reject them with a clear error.
            if (!$this->prefixHelper->isDirective($attr->name)) {
                throw new SyntaxException(
                    sprintf(
                        'Custom element directive "<%s-%s>" only accepts directive attributes '
                        . '(e.g. %s:if, %s:foreach). Regular HTML attribute "%s" is not allowed.',
                        $this->prefixHelper->getPrefix(),
                        $node->name,
                        $this->prefixHelper->getPrefix(),
                        $this->prefixHelper->getPrefix(),
                        $attr->name,
                    ),
                );
            }

            $directiveAttrs[] = $attr;
        }

        // Synthesize the s:NAME directive attribute carrying the expression.
        // This is prepended so that DirectiveExtractionPass processes it first (OUTPUT directives
        // wrap children; control flow directives then wrap everything, giving the right nesting).
        $synthesizedAttr = new AttributeNode(
            name: $this->prefixHelper->buildName($node->name),
            value: AttributeValue::static($expression),
            line: $node->line,
            column: $node->column,
        );

        $fragment = new FragmentNode(
            attributes: [$synthesizedAttr, ...$directiveAttrs],
            children: $node->children,
            line: $node->line,
            column: $node->column,
        );

        $fragment->inheritTemplatePathFrom($node);

        return NodeAction::replace($fragment);
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
