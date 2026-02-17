<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Helper;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Directive\Interface\ContentWrappingDirectiveInterface;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;
use Sugar\Core\Exception\Helper\DidYouMean;
use Sugar\Core\Extension\DirectiveRegistryInterface;

/**
 * Classifies and validates directive attributes against the directive registry.
 */
final readonly class DirectiveClassifier
{
    /**
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Core\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(
        private DirectiveRegistryInterface $registry,
        private DirectivePrefixHelper $prefixHelper,
    ) {
    }

    /**
     * Check whether an attribute name is a directive, optionally excluding inheritance directives.
     */
    public function isDirectiveAttribute(string $attributeName, bool $allowInheritanceAttributes = true): bool
    {
        if (!$this->prefixHelper->isDirective($attributeName)) {
            return false;
        }

        return !(!$allowInheritanceAttributes && $this->prefixHelper->isInheritanceAttribute($attributeName));
    }

    /**
     * Resolve the directive name from an attribute.
     *
     * Returns null when the attribute is not a directive under the current policy.
     */
    public function directiveName(string $attributeName, bool $allowInheritanceAttributes = true): ?string
    {
        if (!$this->isDirectiveAttribute($attributeName, $allowInheritanceAttributes)) {
            return null;
        }

        return $this->prefixHelper->stripPrefix($attributeName);
    }

    /**
     * Resolve the registered directive compiler for an attribute.
     *
     * Returns null if the attribute is not a directive or the directive is unknown.
     */
    public function compilerForAttribute(
        string $attributeName,
        bool $allowInheritanceAttributes = true,
    ): ?DirectiveInterface {
        $name = $this->directiveName($attributeName, $allowInheritanceAttributes);
        if ($name === null || !$this->registry->has($name)) {
            return null;
        }

        return $this->registry->get($name);
    }

    /**
     * Check if a directive attribute should be treated as a non-pass-through directive.
     */
    public function isNonPassThroughDirectiveAttribute(
        string $attributeName,
        bool $allowInheritanceAttributes = true,
    ): bool {
        if (!$this->isDirectiveAttribute($attributeName, $allowInheritanceAttributes)) {
            return false;
        }

        $compiler = $this->compilerForAttribute($attributeName, $allowInheritanceAttributes);
        if (!$compiler instanceof DirectiveInterface) {
            return true;
        }

        if ($compiler instanceof ContentWrappingDirectiveInterface) {
            return true;
        }

        return $compiler->getType() !== DirectiveType::PASS_THROUGH;
    }

    /**
     * Check if a directive attribute maps to a control flow compiler.
     */
    public function isControlFlowDirectiveAttribute(string $attributeName): bool
    {
        $compiler = $this->compilerForAttribute($attributeName);
        if (!$compiler instanceof DirectiveInterface) {
            return false;
        }

        return $compiler->getType() === DirectiveType::CONTROL_FLOW;
    }

    /**
     * Validate directive attributes on nodes and their children.
     *
     * @param array<\Sugar\Core\Ast\Node> $nodes
     */
    public function validateUnknownDirectivesInNodes(
        array $nodes,
        CompilationContext $context,
        bool $allowInheritanceAttributes = true,
    ): void {
        foreach ($nodes as $node) {
            if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof ComponentNode) {
                foreach ($node->attributes as $attr) {
                    $this->validateDirectiveAttribute($attr, $context, $allowInheritanceAttributes);
                }

                $this->validateUnknownDirectivesInNodes($node->children, $context, $allowInheritanceAttributes);
            }
        }
    }

    /**
     * Validate a single directive attribute and throw if the directive is unknown.
     */
    public function validateDirectiveAttribute(
        AttributeNode $attr,
        CompilationContext $context,
        bool $allowInheritanceAttributes = true,
    ): void {
        $name = $this->directiveName($attr->name, $allowInheritanceAttributes);
        if ($name === null) {
            return;
        }

        if (
            $this->compilerForAttribute(
                $attr->name,
                $allowInheritanceAttributes,
            ) instanceof DirectiveInterface
        ) {
            return;
        }

        throw $context->createSyntaxExceptionForAttribute(
            $this->buildUnknownDirectiveMessage($name),
            $attr,
            $attr->line,
            $this->directiveColumn($attr),
        );
    }

    /**
     * Build an unknown directive error message with suggestions.
     */
    private function buildUnknownDirectiveMessage(string $name): string
    {
        $registrySuggestion = DidYouMean::suggest($name, array_keys($this->registry->all()));
        $inheritanceSuggestion = DidYouMean::suggest($name, $this->prefixHelper->inheritanceDirectiveNames());

        $suggestion = $registrySuggestion ?? $inheritanceSuggestion;
        if ($registrySuggestion !== null && $inheritanceSuggestion !== null) {
            $registryDistance = levenshtein($name, $registrySuggestion);
            $inheritanceDistance = levenshtein($name, $inheritanceSuggestion);
            if ($inheritanceDistance <= $registryDistance) {
                $suggestion = $inheritanceSuggestion;
            }
        }

        $message = sprintf('Unknown directive "%s"', $name);
        if ($suggestion !== null) {
            $message .= sprintf('. Did you mean "%s"?', $suggestion);
        }

        return $message;
    }

    /**
     * Place the error column on the directive name instead of the prefix.
     */
    private function directiveColumn(AttributeNode $attr): int
    {
        if (!$this->prefixHelper->isDirective($attr->name)) {
            return $attr->column;
        }

        $offset = strlen($this->prefixHelper->getPrefix()) + 1;

        return $attr->column + $offset;
    }
}
