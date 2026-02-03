<?php
declare(strict_types=1);

namespace Sugar\Config;

/**
 * Configuration for Sugar template engine
 *
 * Allows customizing directive prefixes, element prefix, and component paths.
 * All properties are readonly for immutability.
 */
final readonly class SugarConfig
{
    /**
     * Create new configuration
     *
     * @param string $directivePrefix Prefix for directives (e.g., 's' for s:if, 'x' for x:if)
     * @param string $elementPrefix Prefix for custom elements (e.g., 's-' for s-template, s-button)
     * @param array<string> $componentPaths Paths to scan for component templates
     */
    public function __construct(
        public string $directivePrefix = 's',
        public string $elementPrefix = 's-',
        public array $componentPaths = [],
    ) {
    }

    /**
     * Get the fragment element name
     *
     * @return string Fragment element name (e.g., "s-template", "x-template")
     */
    public function getFragmentElement(): string
    {
        return $this->elementPrefix . 'template';
    }

    /**
     * Named constructor for creating config with custom prefix
     *
     * Element prefix will be automatically derived as "{prefix}-"
     *
     * @param string $prefix Directive prefix
     * @return self New configuration instance
     */
    public static function withPrefix(string $prefix): self
    {
        return new self(
            directivePrefix: $prefix,
            elementPrefix: $prefix . '-',
        );
    }
}
