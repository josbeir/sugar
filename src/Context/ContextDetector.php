<?php
declare(strict_types=1);

namespace Sugar\Context;

use Sugar\Enum\OutputContext;
use Sugar\Enum\State;

/**
 * Context detector using state machine to determine output context
 */
final class ContextDetector implements ContextDetectorInterface
{
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'data', 'poster'];

    /**
     * Detect context from position in template source
     *
     * @param int $position Position in the source string
     * @param string $source Template source code
     * @return \Sugar\Enum\OutputContext The detected output context
     */
    public function detect(int $position, string $source): OutputContext
    {
        // Scan backwards from position to determine context
        $state = $this->getState($position, $source);

        return match ($state) {
            State::IN_SCRIPT_TAG => OutputContext::JAVASCRIPT,
            State::IN_STYLE_TAG => OutputContext::CSS,
            State::IN_ATTRIBUTE_VALUE => $this->detectAttributeContext($position, $source),
            State::IN_ATTRIBUTE => OutputContext::HTML_ATTRIBUTE,
            default => OutputContext::HTML,
        };
    }

    /**
     * Determine state at given position
     *
     * @param int $position Position to check
     * @param string $source Template source
     * @return \Sugar\Enum\State The parser state
     */
    private function getState(int $position, string $source): State
    {
        $before = substr($source, 0, $position);

        // Check if we're inside a script tag
        $lastScriptOpen = strrpos($before, '<script');
        $lastScriptClose = strrpos($before, '</script>');

        if ($lastScriptOpen !== false && ($lastScriptClose === false || $lastScriptOpen > $lastScriptClose)) {
            // Make sure the opening tag is complete
            $tagEnd = strpos($source, '>', $lastScriptOpen);
            if ($tagEnd !== false && $tagEnd < $position) {
                return State::IN_SCRIPT_TAG;
            }
        }

        // Check if we're inside a style tag
        $lastStyleOpen = strrpos($before, '<style');
        $lastStyleClose = strrpos($before, '</style>');

        if ($lastStyleOpen !== false && ($lastStyleClose === false || $lastStyleOpen > $lastStyleClose)) {
            $tagEnd = strpos($source, '>', $lastStyleOpen);
            if ($tagEnd !== false && $tagEnd < $position) {
                return State::IN_STYLE_TAG;
            }
        }

        // Check if we're inside an attribute value
        if ($this->isInAttributeValue($position, $source)) {
            return State::IN_ATTRIBUTE_VALUE;
        }

        // Check if we're inside a tag (but not in attribute value)
        /**
         * Check if position is inside attribute value
         *
         * @param int $position Position to check
         * @param string $source Template source
         * @return bool True if inside attribute value
         */
        if ($this->isInTag($position, $source)) {
            return State::IN_ATTRIBUTE;
        }

        return State::HTML_CONTENT;
    }

    /**
     * Check if position is inside an attribute value
     *
     * @param int $position Position to check
     * @param string $source Template source
     * @return bool True if inside attribute value
     */
    private function isInAttributeValue(int $position, string $source): bool
    {
        $before = substr($source, 0, $position);

        // Find the last opening quote before our position
        $lastDoubleQuote = strrpos($before, '"');
        $lastSingleQuote = strrpos($before, "'");

        if ($lastDoubleQuote === false && $lastSingleQuote === false) {
            return false;
        }

        $quoteChar = $lastDoubleQuote > ($lastSingleQuote ?: 0) ? '"' : "'";

        /**
         * Check if position is inside tag
         *
         * @param int $position Position to check
         * @param string $source Template source
         * @return bool True if inside tag
         */
        // Check if there's a matching closing quote after our position
        $after = substr($source, $position);
        $nextQuote = strpos($after, $quoteChar);

        return $nextQuote !== false;
    }

    /**
     * Check if position is inside a tag
     *
     * @param int $position Position to check
     * @param string $source Template source
     * @return bool True if inside tag
     */
    private function isInTag(int $position, string $source): bool
    {
        $before = substr($source, 0, $position);
        /**
         * Detect context for attribute value
         *
         * @param int $position Position in attribute
         * @param string $source Template source
         * @return \Sugar\Context\OutputContext The detected context
         */

        $lastTagOpen = strrpos($before, '<');
        $lastTagClose = strrpos($before, '>');

        return $lastTagOpen !== false && ($lastTagClose === false || $lastTagOpen > $lastTagClose);
    }

    /**
     * Detect specific context for an attribute value
     *
     * @param int $position Position in attribute
     * @param string $source Template source
     * @return \Sugar\Enum\OutputContext The detected context
     */
    private function detectAttributeContext(int $position, string $source): OutputContext
    {
        // Find the attribute name
        $before = substr($source, 0, $position);

        // Find the last = sign before the quote
        $lastEquals = strrpos($before, '=');
        if ($lastEquals === false) {
            return OutputContext::HTML_ATTRIBUTE;
        }

        // Extract attribute name (word before the =)
        $beforeEquals = substr($before, 0, $lastEquals);
        if (preg_match('/(\w+)\s*=?\s*$/', $beforeEquals, $matches)) {
            $attrName = strtolower($matches[1]);

            // Check if it's a URL attribute
            if (in_array($attrName, self::URL_ATTRIBUTES)) {
                return OutputContext::URL;
            }

            // Check if it's an event handler (onclick, onload, etc.)
            if (str_starts_with($attrName, 'on')) {
                return OutputContext::JAVASCRIPT;
            }
        }

        return OutputContext::HTML_ATTRIBUTE;
    }
}
