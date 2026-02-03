<?php
declare(strict_types=1);

namespace Sugar\Config;

/**
 * Configuration for Sugar template engine
 *
 * Allows customizing directive prefixes and fragment element names.
 * All properties are readonly for immutability.
 */
final readonly class SugarConfig
{
    /**
     * Create new configuration
     *
     * @param string $directivePrefix Prefix for directives (e.g., 's' for s:if, 'x' for x:if)
     * @param string|null $fragmentElement Name of fragment element (defaults to "{prefix}-template")
     */
    public function __construct(
        public string $directivePrefix = 's',
        public ?string $fragmentElement = null,
    ) {
    }

    /**
     * Get the fragment element name
     *
     * Returns custom fragment element name if set, otherwise derives from prefix
     *
     * @return string Fragment element name (e.g., "s-template", "x-template")
     */
    public function getFragmentElement(): string
    {
        return $this->fragmentElement ?? $this->directivePrefix . '-template';
    }

    /**
     * Named constructor for creating config with custom prefix
     *
     * Fragment element name will be automatically derived as "{prefix}-template"
     *
     * @param string $prefix Directive prefix
     * @return self New configuration instance
     */
    public static function withPrefix(string $prefix): self
    {
        return new self(directivePrefix: $prefix);
    }
}
