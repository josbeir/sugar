<?php
declare(strict_types=1);

namespace Sugar\Config\Helper;

/**
 * Directive prefix utilities
 *
 * Handles directive name parsing and manipulation for configurable prefixes.
 * Supports custom directive prefixes (s:, x:, v:, etc.) as configured in SugarConfig.
 *
 * Example:
 * ```php
 * $helper = new DirectivePrefixHelper('s');
 * $helper->isDirective('s:if');        // true
 * $helper->stripPrefix('s:foreach');   // 'foreach'
 * $helper->buildName('while');         // 's:while'
 * ```
 */
final readonly class DirectivePrefixHelper
{
    private string $directiveSeparator;

    /**
     * Constructor
     *
     * @param string $prefix Directive prefix (e.g., 's', 'x', 'v')
     */
    public function __construct(
        private string $prefix = 's',
    ) {
        $this->directiveSeparator = $prefix . ':';
    }

    /**
     * Check if attribute name is a directive
     *
     * @param string $attrName Attribute name (e.g., 's:if', 'class')
     */
    public function isDirective(string $attrName): bool
    {
        return str_starts_with($attrName, $this->directiveSeparator);
    }

    /**
     * Strip directive prefix from name
     *
     * @param string $attrName Full attribute name (e.g., 's:if')
     * @return string Directive name without prefix (e.g., 'if')
     */
    public function stripPrefix(string $attrName): string
    {
        if ($this->isDirective($attrName)) {
            return substr($attrName, strlen($this->directiveSeparator));
        }

        return $attrName;
    }

    /**
     * Build full directive name from short name
     *
     * @param string $name Short directive name (e.g., 'if')
     * @return string Full directive name (e.g., 's:if')
     */
    public function buildName(string $name): string
    {
        return $this->directiveSeparator . $name;
    }

    /**
     * Get the configured prefix
     *
     * @return string The directive prefix (e.g., 's')
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the directive separator (prefix + colon)
     *
     * @return string The directive separator (e.g., 's:')
     */
    public function getDirectiveSeparator(): string
    {
        return $this->directiveSeparator;
    }

    /**
     * Check if element/filename uses element prefix
     *
     * Element prefix is used for components:
     * - Tags: <s-button>, <s-card>
     * - Files: s-button.sugar.php, s-card.sugar.php
     *
     * @param string $name Element tag name or filename
     */
    public function hasElementPrefix(string $name): bool
    {
        return str_starts_with($name, $this->prefix . '-');
    }

    /**
     * Strip element prefix from element/filename
     *
     * Examples:
     * - "s-button" -> "button"
     * - "s-card.sugar.php" -> "card.sugar.php"
     *
     * @param string $name Element tag name or filename
     */
    public function stripElementPrefix(string $name): string
    {
        $elementPrefix = $this->prefix . '-';

        return substr($name, strlen($elementPrefix));
    }

    /**
     * Check if attribute is a template inheritance attribute
     *
     * Inheritance attributes (block, append, prepend, extends, include, with) are processed
     * by TemplateInheritancePass and should not be treated as regular directives.
     *
     * @param string $name Attribute name (with or without prefix)
     */
    public function isInheritanceAttribute(string $name): bool
    {
        $stripped = $this->stripPrefix($name);

        return in_array($stripped, $this->inheritanceDirectiveNames(), true);
    }

    /**
     * Return template inheritance directive names without prefix.
     *
     * @return array<string>
     */
    public function inheritanceDirectiveNames(): array
    {
        return ['block', 'append', 'prepend', 'extends', 'include', 'with'];
    }
}
