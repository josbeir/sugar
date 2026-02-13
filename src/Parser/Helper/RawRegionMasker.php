<?php
declare(strict_types=1);

namespace Sugar\Parser\Helper;

use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Runtime\HtmlTagHelper;
use Sugar\Util\Hash;

/**
 * Masks raw directive regions before PHP tokenization.
 *
 * The parser tokenizes the full source before building HTML nodes, so content
 * inside raw regions must be replaced with placeholders first to avoid parsing
 * outputs or PHP blocks within those regions.
 */
final readonly class RawRegionMasker
{
    /**
     * @param \Sugar\Config\SugarConfig $config Parser configuration
     * @param \Sugar\Config\Helper\DirectivePrefixHelper $prefixHelper Prefix helper
     */
    public function __construct(
        private SugarConfig $config,
        private DirectivePrefixHelper $prefixHelper,
    ) {
    }

    /**
     * Replace raw inner regions with opaque placeholders.
     *
     * @return array{source: string, placeholders: array<string, string>}
     */
    public function mask(string $source): array
    {
        $placeholders = [];
        $rawAttribute = $this->prefixHelper->buildName('raw');

        if (!$this->hasRawRegions($source)) {
            return [
                'source' => $source,
                'placeholders' => [],
            ];
        }

        $offset = 0;
        $counter = 0;

        while (preg_match('/<[A-Za-z][A-Za-z0-9-]*\b[^>]*>/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $fullMatch = $match[0][0];
            $tagStart = $match[0][1];
            $tagEnd = $tagStart + strlen($fullMatch);

            preg_match('/^<([A-Za-z][A-Za-z0-9-]*)\b/', $fullMatch, $tagNameMatch);

            $tagName = $tagNameMatch[1];
            $isSelfClosing = str_ends_with(rtrim($fullMatch), '/>')
                || HtmlTagHelper::isSelfClosing($tagName, $this->config->selfClosingTags);

            if ($isSelfClosing || !$this->hasRawAttribute($fullMatch, $rawAttribute)) {
                $offset = $tagEnd;
                continue;
            }

            $closeStart = $this->findMatchingClose($source, $tagName, $tagEnd);
            if ($closeStart === null) {
                $offset = $tagEnd;
                continue;
            }

            $innerContent = substr($source, $tagEnd, $closeStart - $tagEnd);
            $placeholder = sprintf('__SUGAR_RAW_%s__', Hash::make($counter . $innerContent));
            $placeholders[$placeholder] = $innerContent;
            $counter++;

            $source = substr($source, 0, $tagEnd) . $placeholder . substr($source, $closeStart);
            $offset = $tagEnd + strlen($placeholder);
        }

        return [
            'source' => $source,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Determine whether the source may contain raw directive regions.
     */
    public function hasRawRegions(string $source): bool
    {
        return str_contains($source, $this->prefixHelper->buildName('raw'));
    }

    /**
     * Check whether an opening tag contains the configured raw directive attribute.
     */
    private function hasRawAttribute(string $tag, string $rawAttribute): bool
    {
        $pattern =
            '/(?:\s|^)' .
            preg_quote($rawAttribute, '/') .
            '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/';

        return preg_match($pattern, $tag) === 1;
    }

    /**
     * Find matching closing tag position for an opening tag offset.
     */
    private function findMatchingClose(string $source, string $tagName, int $offset): ?int
    {
        $pattern = '/<\/?' . preg_quote($tagName, '/') . '\b[^>]*>/i';
        $depth = 1;

        while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $tag = $match[0][0];
            $tagStart = $match[0][1];
            $offset = $tagStart + strlen($tag);

            if (str_starts_with($tag, '</')) {
                $depth--;
                if ($depth === 0) {
                    return $tagStart;
                }

                continue;
            }

            if (!str_ends_with(rtrim($tag), '/>')) {
                $depth++;
            }
        }

        return null;
    }
}
