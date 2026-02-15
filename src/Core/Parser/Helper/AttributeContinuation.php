<?php
declare(strict_types=1);

namespace Sugar\Core\Parser\Helper;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;

/**
 * Attribute continuation utilities for mixed attribute values.
 */
final class AttributeContinuation
{
    /**
     * Detect if the HTML fragment ends with an open attribute quote.
     *
     * @param string $html HTML fragment
     * @param array<\Sugar\Core\Ast\Node|\Sugar\Core\Parser\Helper\ClosingTagMarker> $htmlNodes Parsed nodes
     * @return array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null
     */
    public static function detectOpenAttribute(string $html, array $htmlNodes): ?array
    {
        $lastOpen = self::findLastOpenAttribute($html);
        if ($lastOpen === null) {
            return null;
        }

        $attrName = $lastOpen['name'];
        $quote = $lastOpen['quote'];

        if ($attrName === null) {
            return null;
        }

        $element = null;
        foreach (array_reverse($htmlNodes) as $node) {
            if ($node instanceof ElementNode) {
                $element = $node;
                break;
            }
        }

        if (!$element instanceof ElementNode) {
            return null;
        }

        foreach ($element->attributes as $index => $attr) {
            if ($attr->name === $attrName) {
                return ['element' => $element, 'attrIndex' => $index, 'quote' => $quote];
            }
        }

        return null;
    }

    /**
     * @return array{name: string, quote: string|null}|null
     */
    private static function findLastOpenAttribute(string $html): ?array
    {
        $len = strlen($html);
        $pos = 0;

        while ($pos < $len) {
            while ($pos < $len && !self::isAttributeNameStart($html[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                break;
            }

            $nameStart = $pos;
            $pos++;
            while ($pos < $len && self::isAttributeNamePart($html[$pos])) {
                $pos++;
            }

            $attrName = substr($html, $nameStart, $pos - $nameStart);

            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                continue;
            }

            if ($html[$pos] !== '=') {
                continue;
            }

            $pos++;
            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                return ['name' => $attrName, 'quote' => null];
            }

            $quote = $html[$pos];
            if ($quote === '"' || $quote === "'") {
                $pos++;
                while ($pos < $len) {
                    $char = $html[$pos];
                    if ($char === $quote && !self::isEscapedQuote($html, $pos)) {
                        $pos++;
                        continue 2;
                    }

                    $pos++;
                }

                return ['name' => $attrName, 'quote' => $quote];
            }

            while ($pos < $len && !self::isUnquotedValueDelimiter($html[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                return ['name' => $attrName, 'quote' => null];
            }
        }

        return null;
    }

    /**
     * Consume HTML after an inline attribute output and append to the pending value.
     *
     * @param string $html HTML fragment
     * @param array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null} $pendingAttribute
     * @param \Sugar\Core\Parser\Helper\NodeFactory $nodeFactory Node factory
     * @return array{0: string, 1: array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null}
     */
    public static function consumeAttributeContinuation(
        string $html,
        array $pendingAttribute,
        NodeFactory $nodeFactory,
    ): array {
        $element = $pendingAttribute['element'];
        $attrIndex = $pendingAttribute['attrIndex'];
        $quote = $pendingAttribute['quote'];
        $attribute = $element->attributes[$attrIndex] ?? null;

        if (!$attribute instanceof AttributeNode) {
            return [$html, null];
        }

        if ($quote === null) {
            $endPos = strcspn($html, " >\t\n\r/");
            $valuePart = substr($html, 0, $endPos);
            self::appendAttributeValuePart($attribute, $valuePart);

            self::normalizeAttributeValue($attribute);

            [$remaining, $pending] = self::applyAttributeContinuation(
                substr($html, $endPos),
                $element,
                $nodeFactory,
            );

            return [$remaining, $pending];
        }

        $quotePos = strpos($html, $quote);
        if ($quotePos === false) {
            self::appendAttributeValuePart($attribute, $html);

            return ['', $pendingAttribute];
        }

        $valuePart = substr($html, 0, $quotePos);
        self::appendAttributeValuePart($attribute, $valuePart);

        self::normalizeAttributeValue($attribute);

        [$remaining, $pending] = self::applyAttributeContinuation(
            substr($html, $quotePos + 1),
            $element,
            $nodeFactory,
        );

        return [$remaining, $pending];
    }

    /**
     * Append a value part to an attribute (string or OutputNode).
     */
    public static function appendAttributeValuePart(AttributeNode $attribute, string|OutputNode $part): void
    {
        if ($part === '') {
            return;
        }

        if ($attribute->value->isBoolean() || ($attribute->value->isStatic() && $attribute->value->static === '')) {
            $parts = [];
        } else {
            $parts = $attribute->value->toParts() ?? [];
        }

        $parts[] = $part;
        $attribute->value = AttributeValue::parts($parts);
    }

    /**
     * Collapse single-part arrays back to string/OutputNode values.
     */
    public static function normalizeAttributeValue(AttributeNode $attribute): void
    {
        $parts = $attribute->value->toParts();
        if ($parts === null || count($parts) !== 1) {
            return;
        }

        $part = $parts[0];
        $attribute->value = $part instanceof OutputNode
            ? AttributeValue::output($part)
            : AttributeValue::static($part);
    }

    /**
     * Continue parsing attributes after an inline output attribute value.
     *
     * @return array{0: string, 1: array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null}
     */
    private static function applyAttributeContinuation(
        string $html,
        ElementNode $element,
        NodeFactory $nodeFactory,
    ): array {
        $pos = 0;
        $len = strlen($html);
        $pendingAttribute = null;

        if ($pos < $len && ($html[$pos] === '"' || $html[$pos] === "'")) {
            $pos++;
        }

        while ($pos < $len) {
            $char = $html[$pos];

            if ($char === '>') {
                $pos++;
                break;
            }

            if ($char === '/' && isset($html[$pos + 1]) && $html[$pos + 1] === '>') {
                $element->selfClosing = true;
                $pos += 2;
                break;
            }

            if (ctype_space($char)) {
                $pos++;
                continue;
            }

            $name = '';
            while ($pos < $len && !self::isAttributeDelimiter($html[$pos])) {
                $name .= $html[$pos++];
            }

            if ($name === '') {
                break;
            }

            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }

            $value = null;
            if ($pos < $len && $html[$pos] === '=') {
                $pos++;

                while ($pos < $len && ctype_space($html[$pos])) {
                    $pos++;
                }

                if ($pos < $len) {
                    $quote = $html[$pos];
                    if ($quote === '"' || $quote === "'") {
                        $pos++;
                        $value = '';
                        while ($pos < $len && $html[$pos] !== $quote) {
                            if ($html[$pos] === '\\' && isset($html[$pos + 1]) && $html[$pos + 1] === $quote) {
                                $value .= $quote;
                                $pos += 2;
                            } else {
                                $value .= $html[$pos++];
                            }
                        }

                        if ($pos < $len) {
                            $pos++;
                        } else {
                            $pendingAttribute = [
                                'element' => $element,
                                'attrIndex' => count($element->attributes),
                                'quote' => $quote,
                            ];
                        }
                    } else {
                        $value = '';
                        while ($pos < $len && !self::isUnquotedValueDelimiter($html[$pos])) {
                            $value .= $html[$pos++];
                        }

                        if ($pos >= $len) {
                            $pendingAttribute = [
                                'element' => $element,
                                'attrIndex' => count($element->attributes),
                                'quote' => null,
                            ];
                        }
                    }
                } else {
                    $value = '';
                    $pendingAttribute = [
                        'element' => $element,
                        'attrIndex' => count($element->attributes),
                        'quote' => null,
                    ];
                }
            }

            $element->attributes[] = $nodeFactory->attribute($name, $value, $element->line, $element->column);

            if ($pendingAttribute !== null) {
                break;
            }
        }

        return [substr($html, $pos), $pendingAttribute];
    }

    /**
     * Check whether a character can start an attribute name.
     */
    private static function isAttributeNameStart(string $char): bool
    {
        return ctype_alpha($char) || $char === '_' || $char === ':';
    }

    /**
     * Check whether a character is valid inside an attribute name.
     */
    private static function isAttributeNamePart(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === ':' || $char === '.' || $char === '-';
    }

    /**
     * Check whether a character terminates an attribute token.
     */
    private static function isAttributeDelimiter(string $char): bool
    {
        return in_array($char, ['=', '>', '/', ' ', "\t", "\n", "\r"], true);
    }

    /**
     * Check whether a character terminates an unquoted attribute value token.
     */
    private static function isUnquotedValueDelimiter(string $char): bool
    {
        return in_array($char, ['>', '/', ' ', "\t", "\n", "\r"], true);
    }

    /**
     * Determine if a quote character is escaped by an odd number of backslashes.
     */
    private static function isEscapedQuote(string $html, int $quotePos): bool
    {
        $backslashCount = 0;
        for ($scan = $quotePos - 1; $scan >= 0 && $html[$scan] === '\\'; $scan--) {
            $backslashCount++;
        }

        return $backslashCount % 2 === 1;
    }
}
