<?php
declare(strict_types=1);

namespace Sugar\Core\Config;

/**
 * Configuration for Sugar template engine
 *
 * Allows customizing directive and element prefixes.
 * All properties are readonly for immutability.
 */
final readonly class SugarConfig
{
    /**
     * Default list of HTML void/self-closing tags.
     *
     * @var array<string>
     */
    public const DEFAULT_SELF_CLOSING_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Create new configuration
     *
     * @param string $directivePrefix Prefix for directives (e.g., 's' for s:if, 'x' for x:if)
     * @param string $elementPrefix Prefix for custom elements (e.g., 's-' for s-template, s-button)
     * @param string|null $fragmentElement Custom fragment element tag (optional)
     * @param array<string> $selfClosingTags List of HTML void/self-closing tags
     */
    public function __construct(
        public string $directivePrefix = 's',
        public string $elementPrefix = 's-',
        public ?string $fragmentElement = null,
        public array $selfClosingTags = self::DEFAULT_SELF_CLOSING_TAGS,
    ) {
    }

    /**
     * Get the fragment element name
     *
     * @return string Fragment element name (e.g., "s-template", "x-template")
     */
    public function getFragmentElement(): string
    {
        return $this->fragmentElement ?? $this->elementPrefix . 'template';
    }

    /**
     * Create a copy of the config with a custom fragment element name.
     */
    public function withFragmentElement(string $fragmentElement): self
    {
        return new self(
            directivePrefix: $this->directivePrefix,
            elementPrefix: $this->elementPrefix,
            fragmentElement: $fragmentElement,
            selfClosingTags: $this->selfClosingTags,
        );
    }

    /**
     * Create a copy of the config with a custom self-closing tag list.
     *
     * @param array<string> $selfClosingTags List of HTML void/self-closing tags
     * @return self New configuration instance
     */
    public function withSelfClosingTags(array $selfClosingTags): self
    {
        return new self(
            directivePrefix: $this->directivePrefix,
            elementPrefix: $this->elementPrefix,
            fragmentElement: $this->fragmentElement,
            selfClosingTags: $selfClosingTags,
        );
    }

    /**
     * Named constructor for creating config with custom prefix
     *
     * Element prefix will be automatically derived as "{prefix}-"
     *
     * @param string $prefix Directive prefix
     * @param array<string>|null $selfClosingTags List of HTML void/self-closing tags
     * @return self New configuration instance
     */
    public static function withPrefix(string $prefix, ?array $selfClosingTags = null): self
    {
        return new self(
            directivePrefix: $prefix,
            elementPrefix: $prefix . '-',
            fragmentElement: null,
            selfClosingTags: $selfClosingTags ?? self::DEFAULT_SELF_CLOSING_TAGS,
        );
    }
}
