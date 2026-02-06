<?php
declare(strict_types=1);

namespace Sugar\Escape;

use InvalidArgumentException;
use Sugar\Enum\OutputContext;

/**
 * Context-aware escaper for XSS prevention
 */
final class Escaper implements EscaperInterface
{
    /**
     * Escape value based on output context
     *
     * @param mixed $value Value to escape
     * @param \Sugar\Enum\OutputContext $context Output context
     * @return string Escaped value
     */
    public function escape(mixed $value, OutputContext $context): string
    {
        return match ($context) {
            OutputContext::HTML => $this->escapeHtml($value),
            OutputContext::HTML_ATTRIBUTE => $this->escapeAttribute($value),
            OutputContext::JAVASCRIPT => $this->escapeJs($value),
            OutputContext::JSON => $this->escapeJson($value),
            OutputContext::CSS => $this->escapeCss($value),
            OutputContext::URL => $this->escapeUrl($value),
            OutputContext::RAW => $this->escapeRaw($value),
        };
    }

    /**
     * Generate inline PHP code for escaping (compile-time optimization)
     * Returns a PHP expression that can be embedded directly in compiled templates
     *
     * @param string $expression PHP expression to escape
     * @param \Sugar\Enum\OutputContext $context Output context
     * @return string PHP code expression
     */
    public function generateEscapeCode(string $expression, OutputContext $context): string
    {
        $htmlFlags = 'ENT_QUOTES | ENT_HTML5';
        $jsonFlags = 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT';

        return match ($context) {
            OutputContext::HTML => sprintf(
                "htmlspecialchars((string)(%s), %s, 'UTF-8')",
                $expression,
                $htmlFlags,
            ),
            OutputContext::HTML_ATTRIBUTE => sprintf(
                "htmlspecialchars((string)(%s), %s, 'UTF-8')",
                $expression,
                $htmlFlags,
            ),
            OutputContext::JAVASCRIPT => sprintf('json_encode(%s, %s)', $expression, $jsonFlags),
            OutputContext::JSON => sprintf('json_encode(%s, JSON_HEX_TAG | JSON_HEX_AMP)', $expression),
            OutputContext::CSS => sprintf('\Sugar\Runtime\escapeCss((string)(%s))', $expression),
            OutputContext::URL => sprintf('rawurlencode((string)(%s))', $expression),
            OutputContext::RAW => $expression,
        };
    }

    /**
     * Escape HTML entities
     *
     * @param mixed $value Value to escape
     * @return string Escaped HTML
     */
    private function escapeHtml(mixed $value): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            throw new InvalidArgumentException('Cannot auto-escape arrays/objects in HTML context');
        }

        assert($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString')));

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape HTML attribute value
     *
     * @param mixed $value Value to escape
     * @return string Escaped attribute
     */
    private function escapeAttribute(mixed $value): string
    {
        // Attributes use same escaping as HTML but we're explicit about context
        assert($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString')));

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape JavaScript value
     *
     * @param mixed $value Value to escape
     * @return string Escaped JavaScript
     */
    private function escapeJs(mixed $value): string
    {
        // Auto-detect: primitives vs objects/arrays
        if (is_scalar($value) || $value === null) {
            return json_encode(
                $value,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            );
        }

        // Arrays/objects become JSON automatically
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * Escape JSON value
     *
     * @param mixed $value Value to escape
     * @return string Escaped JSON
     */
    private function escapeJson(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * Escape CSS value
     *
     * @param mixed $value Value to escape
     * @return string Escaped CSS
     */
    private function escapeCss(mixed $value): string
    {
        assert($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString')));

        $string = (string)$value;

        // Remove potentially dangerous CSS constructs
        $string = preg_replace('/javascript:/i', '', $string) ?? $string;
        $string = preg_replace('/expression\s*\(/i', '', $string) ?? $string;
        $string = preg_replace('/import\s+/i', '', $string) ?? $string;
        $string = preg_replace('/@import/i', '', $string) ?? $string;

        // Escape special CSS characters
        $result = preg_replace_callback('/[^a-zA-Z0-9\s\-_.,#]/', function (array $matches): string {
            return '\\' . dechex(ord($matches[0]));
        }, $string);

        return $result ?? $string;
    }

    /**
     * Escape URL value
     *
     * @param mixed $value Value to escape
     * @return string Escaped URL
     */
    private function escapeUrl(mixed $value): string
    {
        assert($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString')));

        return rawurlencode((string)$value);
    }

    /**
     * No escaping (raw output)
     *
     * @param mixed $value Value to convert
     * @return string String representation
     */
    private function escapeRaw(mixed $value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }

        return '';
    }
}
