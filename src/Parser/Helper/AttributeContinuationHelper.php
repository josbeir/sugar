<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;

/**
 * Helpers for parsing and continuing mixed attribute values.
 */
final class AttributeContinuationHelper
{
    /**
     * Detect if the HTML fragment ends with an open attribute quote.
     *
     * @param string $html HTML fragment
     * @param array<\Sugar\Ast\Node|\Sugar\Parser\Helper\ClosingTagMarker> $htmlNodes Parsed nodes
     * @return array{element: \Sugar\Ast\ElementNode, attrIndex: int, quote: string|null}|null
     */
    public static function detectOpenAttribute(string $html, array $htmlNodes): ?array
    {
        $attrName = null;
        $quote = null;

        if (preg_match('/([A-Za-z_:][\\w:.-]*)\\s*=\\s*$/', $html, $matches) === 1) {
            $attrName = $matches[1];
        } else {
            $pattern = '/([A-Za-z_:][\\w:.-]*)\\s*=\\s*(["\"])|([A-Za-z_:][\\w:.-]*)\\s*=\\s*(\')/';
            if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE) >= 1) {
                $lastIndex = count($matches[0]) - 1;
                $attrName = $matches[1][$lastIndex][0] ?: $matches[3][$lastIndex][0];
                $quote = $matches[2][$lastIndex][0] ?: $matches[4][$lastIndex][0];
                $startPos = $matches[0][$lastIndex][1] + strlen($matches[0][$lastIndex][0]);
                if (strpos($html, $quote, $startPos) !== false) {
                    return null;
                }
            }
        }

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
     * Consume HTML after an inline attribute output and append to the pending value.
     *
     * @param string $html HTML fragment
     * @param array{element: \Sugar\Ast\ElementNode, attrIndex: int, quote: string|null} $pendingAttribute
     * @return array{0: string, 1: array{element: \Sugar\Ast\ElementNode, attrIndex: int, quote: string|null}|null}
     */
    public static function consumeAttributeContinuation(string $html, array $pendingAttribute): array
    {
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

            [$remaining, $pending] = self::applyAttributeContinuation(substr($html, $endPos), $element);

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

        [$remaining, $pending] = self::applyAttributeContinuation(substr($html, $quotePos + 1), $element);

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

        if ($attribute->value === null || $attribute->value === '') {
            $attribute->value = [$part];

            return;
        }

        if (is_array($attribute->value)) {
            $attribute->value[] = $part;

            return;
        }

        $attribute->value = [$attribute->value, $part];
    }

    /**
     * Collapse single-part arrays back to string/OutputNode values.
     */
    public static function normalizeAttributeValue(AttributeNode $attribute): void
    {
        if (!is_array($attribute->value) || count($attribute->value) !== 1) {
            return;
        }

        $attribute->value = $attribute->value[0];
    }

    /**
     * Continue parsing attributes after an inline output attribute value.
     *
     * @return array{0: string, 1: array{element: \Sugar\Ast\ElementNode, attrIndex: int, quote: string|null}|null}
     */
    private static function applyAttributeContinuation(string $html, ElementNode $element): array
    {
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
            while ($pos < $len && !in_array($html[$pos], ['=', '>', '/', ' ', "\t", "\n", "\r"], true)) {
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
                        while ($pos < $len && !in_array($html[$pos], ['>', '/', ' ', "\t", "\n", "\r"], true)) {
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

            $element->attributes[] = new AttributeNode($name, $value, $element->line, $element->column);

            if ($pendingAttribute !== null) {
                break;
            }
        }

        return [substr($html, $pos), $pendingAttribute];
    }
}
