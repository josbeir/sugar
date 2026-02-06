<?php
declare(strict_types=1);

namespace Sugar\Runtime;

use RuntimeException;

/**
 * Helper for dynamic HTML tag manipulation
 *
 * Provides utilities for validating and sanitizing dynamic tag names.
 */
final class HtmlTagHelper
{
    /**
     * List of forbidden HTML tags that should never be dynamically rendered
     *
     * These tags pose security risks or have special parsing rules.
     *
     * @var array<string>
     */
    private const FORBIDDEN_TAGS = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'applet',
        'form',
        'input',
        'textarea',
        'button',
        'select',
    ];

    /**
     * Validate and sanitize a dynamic tag name
     *
     * Ensures the tag name:
     * - Contains only alphanumeric characters
     * - Starts with a letter
     * - Is not a forbidden tag
     *
     * @param string $tagName The tag name to validate
     * @return string The validated tag name
     * @throws \RuntimeException If tag name is invalid or forbidden
     */
    public static function validateTagName(string $tagName): string
    {
        $tagName = trim($tagName);

        // Must be non-empty
        if ($tagName === '') {
            throw new RuntimeException('Tag name cannot be empty');
        }

        // Must be valid HTML tag syntax (alphanumeric, starts with letter)
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $tagName)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid tag name: "%s". Tag names must start with a letter ' .
                    'and contain only alphanumeric characters.',
                    $tagName,
                ),
            );
        }

        // Check against forbidden tags
        $lowerTagName = strtolower($tagName);
        if (in_array($lowerTagName, self::FORBIDDEN_TAGS, true)) {
            throw new RuntimeException(
                sprintf(
                    'Forbidden tag name: "%s". This tag cannot be used dynamically for security reasons.',
                    $tagName,
                ),
            );
        }

        return $tagName;
    }

    /**
     * Check if a tag is self-closing
     *
     * @param string $tagName The tag name to check
     * @return bool True if the tag is self-closing
     */
    public static function isSelfClosing(string $tagName): bool
    {
        $selfClosingTags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
            'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];

        return in_array(strtolower($tagName), $selfClosingTags, true);
    }
}
