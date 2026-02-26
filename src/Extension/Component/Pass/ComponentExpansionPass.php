<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Pass;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\ExpressionValidator;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CodeGen\GeneratedAlias;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Extension\Component\Helper\SlotResolver;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Throwable;

/**
 * Converts component invocations into runtime rendering calls.
 *
 * Replaces ComponentNode instances and s:component directives with
 * RuntimeCallNode instances that delegate rendering to ComponentRenderer
 * at runtime. All template compilation, caching, slot resolution, and
 * attribute merging happens at runtime via the shared TemplateRenderer.
 */
final class ComponentExpansionPass implements AstPassInterface
{
    private readonly DirectivePrefixHelper $prefixHelper;

    private readonly string $slotAttrName;

    private readonly DirectiveClassifier $directiveClassifier;

    private readonly SlotResolver $slotResolver;

    /**
     * @param \Sugar\Extension\Component\Loader\ComponentLoaderInterface $loader Component template loader
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Extension registry for directive type checking
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly ComponentLoaderInterface $loader,
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->slotAttrName = $this->prefixHelper->buildName('slot');
        $this->directiveClassifier = new DirectiveClassifier($this->registry, $this->prefixHelper);
        $this->slotResolver = new SlotResolver($this->slotAttrName);
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }

    /**
     * Process nodes after child traversal.
     *
     * Converts all component invocations (ComponentNode and s:component directive)
     * into RuntimeCallNode instances for runtime rendering.
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof ComponentNode) {
            $this->trackComponentDependency($node->name, $context);

            $runtimeCall = $this->createRuntimeComponentCall(
                nameExpression: var_export($node->name, true),
                attributes: $node->attributes,
                children: $node->children,
                line: $node->line,
                column: $node->column,
                context: $context->compilation,
            );

            $runtimeCall->inheritTemplatePathFrom($node);

            return NodeAction::replace($runtimeCall);
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            $result = $this->tryConvertComponentDirective($node, $context->compilation);
            if ($result instanceof RuntimeCallNode) {
                return NodeAction::replace($result);
            }

            // Literal s:component directive returns ComponentNode; convert to runtime call
            if ($result instanceof ComponentNode) {
                $this->trackComponentDependency($result->name, $context);

                $runtimeCall = $this->createRuntimeComponentCall(
                    nameExpression: var_export($result->name, true),
                    attributes: $result->attributes,
                    children: $result->children,
                    line: $result->line,
                    column: $result->column,
                    context: $context->compilation,
                );

                $runtimeCall->inheritTemplatePathFrom($result);

                return NodeAction::replace($runtimeCall);
            }
        }

        return NodeAction::none();
    }

    /**
     * Convert s:component directive on an element/fragment into a runtime call.
     *
     * For literal component names, creates a ComponentNode that will be processed
     * by the pipeline on the next traversal pass. For dynamic expressions, creates
     * a RuntimeCallNode directly.
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $node Node to inspect
     * @param \Sugar\Core\Compiler\CompilationContext|null $context Compilation context for errors
     * @return \Sugar\Core\Ast\ComponentNode|\Sugar\Core\Ast\RuntimeCallNode|null Component or runtime call node, or null if not applicable
     */
    private function tryConvertComponentDirective(
        ElementNode|FragmentNode $node,
        ?CompilationContext $context,
    ): ComponentNode|RuntimeCallNode|null {
        $attrName = $this->prefixHelper->buildName('component');
        $result = AttributeHelper::findAttributeWithIndex($node->attributes, $attrName);

        if ($result === null) {
            return null;
        }

        [$attr, $index] = $result;
        $value = $attr->value->isStatic() ? $attr->value->static : null;

        if ($value === null || $value === '') {
            $message = 'Component name must be a non-empty string.';
            if ($context instanceof CompilationContext) {
                throw $context->createSyntaxExceptionForAttribute($message, $attr);
            }

            throw new SyntaxException($message);
        }

        $attributes = $node->attributes;
        array_splice($attributes, $index, 1);

        $literalName = $this->normalizeComponentName($value);
        if ($literalName !== null) {
            // Return ComponentNode — the pipeline will revisit and convert to RuntimeCallNode
            $componentNode = new ComponentNode(
                name: $literalName,
                attributes: $attributes,
                children: $node->children,
                line: $node->line,
                column: $node->column,
            );

            $componentNode->inheritTemplatePathFrom($node);

            return $componentNode;
        }

        $runtimeCall = $this->createRuntimeComponentCall(
            nameExpression: $value,
            attributes: $attributes,
            children: $node->children,
            line: $node->line,
            column: $node->column,
            context: $context,
        );

        $runtimeCall->inheritTemplatePathFrom($node);

        return $runtimeCall;
    }

    /**
     * Normalize a literal component name or return null for expressions.
     *
    /**
     * Track a component dependency for compile-time cache invalidation.
     *
     * Records the component file path in the dependency tracker so that
     * changes to the component template invalidate the compiled parent template.
     *
     * @param string $componentName Component name to track
     * @param \Sugar\Core\Compiler\Pipeline\PipelineContext $context Pipeline context for tracker access
     */
    private function trackComponentDependency(string $componentName, PipelineContext $context): void
    {
        $tracker = $context->compilation->tracker;
        if (!$tracker instanceof DependencyTracker) {
            return;
        }

        try {
            $filePath = $this->loader->getComponentFilePath($componentName);
            $tracker->addDependency($filePath);
        } catch (Throwable) {
            // Component file not found at compile time — skip tracking
        }
    }

    /**
     * Normalize a raw directive value to a clean component name.
     *
     * Returns the cleaned name for simple identifiers like "alert" or "'alert'",
     * or null when the value contains PHP expressions (e.g. "$componentName").
     */
    private function normalizeComponentName(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([\"\"]).+\1$/s', $trimmed) === 1) {
            $trimmed = substr($trimmed, 1, -1);
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Create a runtime call node for component rendering.
     *
     * Builds a RuntimeCallNode that calls ComponentRenderer::renderComponent()
     * with the component name, bindings, slots, and attributes as arguments.
     *
     * @param string $nameExpression PHP expression for the component name
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Component attributes
     * @param array<\Sugar\Core\Ast\Node> $children Component children (slot content)
     * @param int $line Source line number
     * @param int $column Source column number
     * @param \Sugar\Core\Compiler\CompilationContext|null $context Compilation context for error reporting
     */
    private function createRuntimeComponentCall(
        string $nameExpression,
        array $attributes,
        array $children,
        int $line,
        int $column,
        ?CompilationContext $context,
    ): RuntimeCallNode {
        $categorized = $this->categorizeComponentAttributes($attributes);

        $bindingsExpression = '[]';
        $bindContext = $this->prefixHelper->buildName('bind') . ' attribute';
        if ($categorized['componentBindings'] instanceof AttributeNode) {
            $bindAttribute = $categorized['componentBindings'];
            $bindingsValue = $bindAttribute->value;

            if ($bindingsValue->isBoolean()) {
                $message = sprintf(
                    '%s attribute must have a value (e.g., %s="[\'key\' => $value]")',
                    $this->prefixHelper->buildName('bind'),
                    $this->prefixHelper->buildName('bind'),
                );
                if ($context instanceof CompilationContext) {
                    throw $context->createSyntaxExceptionForAttribute(
                        $message,
                        $bindAttribute,
                    );
                }

                throw new SyntaxException($message);
            }

            if ($bindingsValue->isParts()) {
                $message = sprintf(
                    '%s attribute cannot contain mixed output expressions',
                    $this->prefixHelper->buildName('bind'),
                );
                if ($context instanceof CompilationContext) {
                    throw $context->createSyntaxExceptionForAttribute(
                        $message,
                        $bindAttribute,
                    );
                }

                throw new SyntaxException($message);
            }

            if ($bindingsValue->isOutput()) {
                $output = $bindingsValue->output;
                $bindingsExpression = $output instanceof OutputNode ? $output->expression : '[]';
            } else {
                $bindingsExpression = $bindingsValue->static ?? '[]';
            }

            ExpressionValidator::validateArrayExpression(
                $bindingsExpression,
                $bindContext,
                $context,
                $bindAttribute->line,
                $bindAttribute->column,
            );
        }

        $slots = $this->slotResolver->extract($children);
        $slotsExpression = $this->slotResolver->buildSlotsExpression($slots);
        $slotMetaExpression = $this->slotResolver->buildSlotMetaExpression($slots);
        $attributesExpression = $this->buildRuntimeAttributesExpression(array_merge(
            $categorized['merge'],
            $categorized['attributeDirectives'],
        ));

        return new RuntimeCallNode(
            callableExpression: GeneratedAlias::RUNTIME_ENV
                . '::requireService(' . ComponentRenderer::class . '::class)->renderComponent',
            arguments: [
                $nameExpression,
                $bindingsExpression,
                $slotsExpression,
                $attributesExpression,
                $slotMetaExpression,
            ],
            line: $line,
            column: $column,
        );
    }

    /**
     * Build runtime attributes array expression.
     *
     * Converts attribute nodes to a PHP array expression for runtime attribute merging.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Attributes to convert
     * @return string PHP array expression
     */
    private function buildRuntimeAttributesExpression(array $attributes): string
    {
        if ($attributes === []) {
            return '[]';
        }

        $items = [];
        foreach ($attributes as $attr) {
            $key = var_export($attr->name, true);

            if ($attr->value->isOutput()) {
                $output = $attr->value->output;
                $value = $output instanceof OutputNode ? $output->expression : 'null';
            } elseif ($attr->value->isBoolean()) {
                $value = 'null';
            } else {
                $parts = $attr->value->toParts() ?? [];
                if (count($parts) > 1) {
                    $expressionParts = [];
                    foreach ($parts as $part) {
                        if ($part instanceof OutputNode) {
                            $expressionParts[] = '(' . $part->expression . ')';
                            continue;
                        }

                        $expressionParts[] = var_export($part, true);
                    }

                    $value = implode(' . ', $expressionParts);
                } else {
                    $part = $parts[0] ?? '';
                    $value = $part instanceof OutputNode
                        ? '(' . $part->expression . ')'
                        : var_export($part, true);
                }
            }

            $items[] = $key . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Split component attributes into control-flow, directive, bind and merge buckets.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes Component attributes
     * @return array{
     *   controlFlow: array<\Sugar\Core\Ast\AttributeNode>,
     *   attributeDirectives: array<\Sugar\Core\Ast\AttributeNode>,
     *   componentBindings: \Sugar\Core\Ast\AttributeNode|null,
     *   merge: array<\Sugar\Core\Ast\AttributeNode>
     * }
     */
    private function categorizeComponentAttributes(array $attributes): array
    {
        $controlFlow = [];
        $attributeDirectives = [];
        $componentBindings = null;
        $mergeAttrs = [];

        foreach ($attributes as $attr) {
            $name = $attr->name;

            if ($this->prefixHelper->isDirective($name)) {
                $directiveName = $this->prefixHelper->stripPrefix($name);

                if ($directiveName === 'bind') {
                    $componentBindings = $attr;
                } elseif ($this->directiveClassifier->isControlFlowDirectiveAttribute($name)) {
                    $controlFlow[] = $attr;
                } else {
                    $attributeDirectives[] = $attr;
                }

                continue;
            }

            $mergeAttrs[] = $attr;
        }

        return [
            'controlFlow' => $controlFlow,
            'attributeDirectives' => $attributeDirectives,
            'componentBindings' => $componentBindings,
            'merge' => $mergeAttrs,
        ];
    }
}
