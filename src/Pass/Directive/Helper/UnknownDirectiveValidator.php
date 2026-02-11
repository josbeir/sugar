<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive\Helper;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Context\CompilationContext;
use Sugar\Exception\DidYouMean;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistryInterface;

/**
 * Validates directive attributes against the registry and builds consistent errors.
 */
final readonly class UnknownDirectiveValidator
{
    /**
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(
        private DirectiveRegistryInterface $registry,
        private DirectivePrefixHelper $prefixHelper,
    ) {
    }

    /**
     * Validate directive attributes on nodes and their children.
     *
     * @param array<\Sugar\Ast\Node> $nodes
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
        if (!$this->prefixHelper->isDirective($attr->name)) {
            return;
        }

        if (!$allowInheritanceAttributes && $this->prefixHelper->isInheritanceAttribute($attr->name)) {
            return;
        }

        $name = $this->prefixHelper->stripPrefix($attr->name);
        if ($this->registry->has($name)) {
            return;
        }

        throw $context->createException(
            SyntaxException::class,
            $this->buildUnknownDirectiveMessage($name),
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
