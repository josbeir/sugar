<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Runtime\HtmlTagHelper;

/**
 * Parse HTML fragments into flat node lists for the template parser.
 */
final readonly class HtmlParser
{
    /**
     * @param \Sugar\Config\SugarConfig $config Parser configuration
     * @param \Sugar\Config\Helper\DirectivePrefixHelper $prefixHelper Prefix helper
     * @param \Sugar\Parser\Helper\NodeFactory $nodeFactory Node factory
     */
    public function __construct(
        private SugarConfig $config,
        private DirectivePrefixHelper $prefixHelper,
        private NodeFactory $nodeFactory,
    ) {
    }

    /**
     * Parse HTML string into flat list of nodes and markers.
     *
     * @param string $html HTML content
     * @param int $line Line number
     * @param int $column Column number
     * @return array<\Sugar\Ast\Node|\Sugar\Parser\Helper\ClosingTagMarker> Flat node list
     */
    public function parse(string $html, int $line, int $column): array
    {
        $nodes = [];
        $pos = 0;
        $len = strlen($html);
        $lineStarts = HtmlScanHelper::lineStartsForSource($html);

        while ($pos < $len) {
            $tagStart = strpos($html, '<', $pos);

            if ($tagStart === false) {
                // Rest is text
                if ($pos < $len) {
                    [$textLine, $textColumn] = HtmlScanHelper::resolvePosition(
                        $html,
                        $pos,
                        $line,
                        $column,
                        $lineStarts,
                    );
                    $nodes[] = $this->nodeFactory->text(substr($html, $pos), $textLine, $textColumn);
                }

                break;
            }

            // Text before tag
            if ($tagStart > $pos) {
                [$textLine, $textColumn] = HtmlScanHelper::resolvePosition($html, $pos, $line, $column, $lineStarts);
                $nodes[] = $this->nodeFactory->text(
                    substr($html, $pos, $tagStart - $pos),
                    $textLine,
                    $textColumn,
                );
            }

            // Check for closing tag
            if (isset($html[$tagStart + 1]) && $html[$tagStart + 1] === '/') {
                [$tagName, $endPos] = $this->extractClosingTag($html, $tagStart);
                $nodes[] = $this->nodeFactory->closingTagMarker($tagName);
                $pos = $endPos;
            } elseif (isset($html[$tagStart + 1]) && $html[$tagStart + 1] === '!') {
                // Special cases: <!DOCTYPE>, <!-->, <![CDATA[> - treat as text
                $endPos = strpos($html, '>', $tagStart);
                if ($endPos === false) {
                    $endPos = $len;
                } else {
                    $endPos++;
                }

                [$textLine, $textColumn] = HtmlScanHelper::resolvePosition(
                    $html,
                    $tagStart,
                    $line,
                    $column,
                    $lineStarts,
                );
                $nodes[] = $this->nodeFactory->text(
                    substr($html, $tagStart, $endPos - $tagStart),
                    $textLine,
                    $textColumn,
                );
                $pos = $endPos;
            } else {
                // Opening or self-closing tag
                [$element, $endPos] = $this->extractOpeningTag($html, $tagStart, $line, $column, $lineStarts);
                $nodes[] = $element;
                $pos = $endPos;
            }
        }

        return $nodes;
    }

    /**
     * Extract opening or self-closing HTML tag.
     *
     * @param string $html HTML source
     * @param int $start Position of <
     * @param int $line Line number
     * @param int $column Column number
     * @param array<int, int> $lineStarts Precomputed line starts for this fragment
     * @return array{0: \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode|\Sugar\Ast\ComponentNode, 1: int} Element, Fragment, or Component and position after tag
     */
    private function extractOpeningTag(string $html, int $start, int $line, int $column, array $lineStarts): array
    {
        $pos = $start + 1;
        $len = strlen($html);
        $nameEnd = HtmlScanHelper::readTagNameEnd($html, $pos);
        $tagName = substr($html, $pos, $nameEnd - $pos);
        $pos = $nameEnd;

        // Skip whitespace
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        // Parse attributes
        $attributes = [];
        $selfClosing = false;
        [$elementLine, $elementColumn] = HtmlScanHelper::resolvePosition($html, $start, $line, $column, $lineStarts);

        while ($pos < $len) {
            $char = $html[$pos];

            if ($char === '>') {
                $pos++;
                break;
            }

            if ($char === '/' && isset($html[$pos + 1]) && $html[$pos + 1] === '>') {
                $selfClosing = true;
                $pos += 2;
                break;
            }

            if (ctype_space($char)) {
                $pos++;
                continue;
            }

            // Parse attribute
            $attrStart = $pos;
            [$attrName, $attrValue, $pos] = $this->extractAttribute($html, $pos);
            [$attrLine, $attrColumn] = HtmlScanHelper::resolvePosition(
                $html,
                $attrStart,
                $line,
                $column,
                $lineStarts,
            );
            $attributes[] = $this->nodeFactory->attribute($attrName, $attrValue, $attrLine, $attrColumn);
        }

        $isFragment = $tagName === $this->config->getFragmentElement();
        $isComponent = $this->prefixHelper->hasElementPrefix($tagName) && !$isFragment;

        if (
            !$selfClosing
            && !$isFragment
            && !$isComponent
            && HtmlTagHelper::isSelfClosing($tagName, $this->config->selfClosingTags)
        ) {
            $selfClosing = true;
        }

        $element = $this->nodeFactory->element($tagName, $attributes, $selfClosing, $elementLine, $elementColumn);

        // Handle fragment element (e.g., <s-template>, <x-template>)
        if ($isFragment) {
            $element = $this->nodeFactory->fragment($attributes, $selfClosing, $elementLine, $elementColumn);
        }

        // Handle component elements (e.g., <s-button>, <x-alert>)
        // Components start with elementPrefix but are NOT the fragment element
        if ($isComponent) {
            $componentName = $this->prefixHelper->stripElementPrefix($tagName);
            $element = $this->nodeFactory->component($componentName, $attributes, $elementLine, $elementColumn);
        }

        return [$element, $pos];
    }

    /**
     * Extract closing HTML tag.
     *
     * @param string $html HTML source
     * @param int $start Position of <
     * @return array{0: string, 1: int} Tag name and position after tag
     */
    private function extractClosingTag(string $html, int $start): array
    {
        $pos = $start + 2; // Skip </
        $len = strlen($html);
        $nameEnd = HtmlScanHelper::readTagNameEnd($html, $pos);
        $tagName = substr($html, $pos, $nameEnd - $pos);
        $pos = $nameEnd;

        // Skip to >
        while ($pos < $len && $html[$pos] !== '>') {
            $pos++;
        }

        if ($pos < $len) {
            $pos++; // Skip >
        }

        return [$tagName, $pos];
    }

    /**
     * Extract attribute name and value.
     *
     * @param string $html HTML source
     * @param int $start Starting position
     * @return array{0: string, 1: string|null, 2: int} Name, value, position
     */
    private function extractAttribute(string $html, int $start): array
    {
        $pos = $start;
        $len = strlen($html);
        $name = '';

        // Extract attribute name (including : and - for s:if, data-attr)
        while ($pos < $len && !$this->isAttributeDelimiter($html[$pos])) {
            $name .= $html[$pos++];
        }

        // Skip whitespace
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        // No value (boolean attribute)
        if ($pos >= $len || $html[$pos] !== '=') {
            return [$name, null, $pos];
        }

        $pos++; // Skip =

        // Skip whitespace after =
        while ($pos < $len && ctype_space($html[$pos])) {
            $pos++;
        }

        if ($pos >= $len) {
            return [$name, '', $pos];
        }

        // Extract value
        $quote = $html[$pos];
        if ($quote === '"' || $quote === "'") {
            $pos++; // Skip opening quote
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
                $pos++; // Skip closing quote
            }

            return [$name, $value, $pos];
        }

        // Unquoted value
        $value = '';
        while ($pos < $len && !$this->isUnquotedValueDelimiter($html[$pos])) {
            $value .= $html[$pos++];
        }

        return [$name, $value, $pos];
    }

    /**
     * Check whether a character terminates an attribute name token.
     */
    private function isAttributeDelimiter(string $char): bool
    {
        return in_array($char, ['=', '>', '/', ' ', "\t", "\n", "\r"], true);
    }

    /**
     * Check whether a character terminates an unquoted attribute value.
     */
    private function isUnquotedValueDelimiter(string $char): bool
    {
        return in_array($char, ['>', '/', ' ', "\t", "\n", "\r"], true);
    }
}
