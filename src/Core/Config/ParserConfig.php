<?php
declare(strict_types=1);

namespace Sugar\Core\Config;

/**
 * Parser configuration
 *
 * Configures how the parser processes templates:
 * - Directive attribute prefix (default: 's')
 * - Void elements (HTML5 elements that cannot have children)
 */
final readonly class ParserConfig
{
    private const DEFAULT_VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * @var array<string>
     */
    public array $voidElements;

    /**
     * @param string $directivePrefix Prefix for directive attributes (e.g., 's' for s:if, s:foreach)
     * @param array<string>|null $voidElements HTML5 void elements (replaces defaults if provided)
     * @param array<string> $additionalVoidElements Additional void elements to add to defaults
     */
    public function __construct(
        public string $directivePrefix = 's',
        ?array $voidElements = null,
        array $additionalVoidElements = [],
    ) {
        $this->voidElements = $voidElements ?? array_merge(
            self::DEFAULT_VOID_ELEMENTS,
            $additionalVoidElements,
        );
    }

    /**
     * Check if an attribute name is a directive
     *
     * @param string $attributeName Attribute name (e.g., 's:if', 's:foreach', 'class')
     * @return bool True if attribute is a directive
     */
    public function isDirective(string $attributeName): bool
    {
        $prefix = $this->directivePrefix . ':';

        return str_starts_with($attributeName, $prefix) && strlen($attributeName) > strlen($prefix);
    }

    /**
     * Extract directive name from attribute
     *
     * @param string $attributeName Full attribute name (e.g., 's:if', 's:foreach')
     * @return string|null Directive name ('if', 'foreach') or null if not a directive
     */
    public function extractDirectiveName(string $attributeName): ?string
    {
        if (!$this->isDirective($attributeName)) {
            return null;
        }

        $prefixLength = strlen($this->directivePrefix) + 1; // +1 for ':'

        return substr($attributeName, $prefixLength);
    }

    /**
     * Check if a tag is a void element (cannot have children)
     *
     * @param string $tag Tag name
     * @return bool True if tag is a void element
     */
    public function isVoidElement(string $tag): bool
    {
        return in_array(strtolower($tag), $this->voidElements, true);
    }
}
