<?php
declare(strict_types=1);

namespace Sugar\Escape;

use InvalidArgumentException;
use Sugar\Enum\OutputContext;
use Sugar\Util\ValueNormalizer;

/**
 * Context-aware escaper for XSS prevention
 */
final class Escaper implements EscaperInterface
{
    /**
     * @inheritDoc
     */
    public function escape(mixed $value, OutputContext $context): string
    {
        return match ($context) {
            OutputContext::HTML => self::html($value),
            OutputContext::HTML_ATTRIBUTE => self::attr($value),
            OutputContext::JAVASCRIPT => self::js($value),
            OutputContext::JSON => self::json($value),
            OutputContext::JSON_ATTRIBUTE => self::attrJson($value),
            OutputContext::CSS => self::css($value),
            OutputContext::URL => self::url($value),
            OutputContext::RAW => self::raw($value),
        };
    }

    /**
     * @inheritDoc
     */
    public function generateEscapeCode(string $expression, OutputContext $context): string
    {
        return match ($context) {
            OutputContext::HTML => sprintf(Escaper::class . '::html(%s)', $expression),
            OutputContext::HTML_ATTRIBUTE => sprintf(Escaper::class . '::attr(%s)', $expression),
            OutputContext::JAVASCRIPT => sprintf(Escaper::class . '::js(%s)', $expression),
            OutputContext::JSON => sprintf(Escaper::class . '::json(%s)', $expression),
            OutputContext::JSON_ATTRIBUTE => sprintf(Escaper::class . '::attrJson(%s)', $expression),
            OutputContext::CSS => sprintf(Escaper::class . '::css(%s)', $expression),
            OutputContext::URL => sprintf(Escaper::class . '::url(%s)', $expression),
            OutputContext::RAW => $expression,
        };
    }

    /**
     * Escape HTML entities
     *
     * @param mixed $value Value to escape
     * @return string Escaped HTML
     */
    public static function html(mixed $value): string
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            throw new InvalidArgumentException('Cannot auto-escape arrays/objects in HTML context');
        }

        assert(
            is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString')),
            'HTML attribute escaping expects a scalar, null, or stringable object',
        );

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape HTML attribute value
     *
     * @param mixed $value Value to escape
     * @return string Escaped attribute
     */
    public static function attr(mixed $value): string
    {
        // Attributes use same escaping as HTML but we're explicit about context
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            throw new InvalidArgumentException('Cannot auto-escape arrays/objects in HTML attribute context');
        }

        assert(is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString')));

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape JavaScript value
     *
     * @param mixed $value Value to escape
     * @return string Escaped JavaScript
     */
    public static function js(mixed $value): string
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
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * Escape JSON for HTML attribute output
     *
     * @param mixed $value Value to escape
     * @return string Escaped JSON safe for attribute output
     */
    public static function attrJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        return htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape CSS value
     *
     * @param mixed $value Value to escape
     * @return string Escaped CSS
     */
    public static function css(mixed $value): string
    {
        assert(is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString')));

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
    public static function url(mixed $value): string
    {
        assert(is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString')));

        return rawurlencode((string)$value);
    }

    /**
     * No escaping (raw output)
     *
     * @param mixed $value Value to convert
     * @return string String representation
     */
    public static function raw(mixed $value): string
    {
        return ValueNormalizer::toDisplayString($value);
    }
}
