<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Runtime\HtmlTagHelper;

/**
 * State-machine lexer for Sugar templates.
 *
 * Scans the source character-by-character with a push/pop state stack,
 * producing a flat array of Token instances. The lexer is template-aware:
 * it understands both HTML structure and PHP open/close transitions natively,
 * eliminating the need for a separate HTML parser or attribute continuation logic.
 *
 * States:
 *  - HtmlText:     scanning plain text/content between tags
 *  - HtmlTag:      inside an HTML open/close tag (reading attrs, etc.)
 *  - HtmlAttrVal:  inside a quoted attribute value
 *  - PhpOutput:    inside short echo tags
 *  - PhpBlock:     inside php block tags
 *
 * Raw region handling (s:raw):
 *  Pre-scanned so that content inside raw-marked elements is emitted
 *  as a single RawBody token, never parsed as HTML/PHP.
 *
 * Example:
 *   $lexer  = new Lexer();
 *   $tokens = $lexer->tokenize('<div class="{output}">...</div>');
 */
final class Lexer
{
    private SugarConfig $config;

    private DirectivePrefixHelper $prefixHelper;

    private string $source;

    private int $length;

    private int $pos;

    private int $line;

    private int $column;

    /**
     * @var array<\Sugar\Core\Parser\Token>
     */
    private array $tokens;

    /**
     * @param \Sugar\Core\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(?SugarConfig $config = null)
    {
        $this->config = $config ?? new SugarConfig();
        $this->prefixHelper = new DirectivePrefixHelper($this->config->directivePrefix);
    }

    /**
     * Tokenize a full Sugar template source string into tokens.
     *
     * @param string $source The template source
     * @return array<\Sugar\Core\Parser\Token> Flat array of tokens (always ends with Eof)
     */
    public function tokenize(string $source): array
    {
        $this->source = $source;
        $this->length = strlen($source);
        $this->pos = 0;
        $this->line = 1;
        $this->column = 1;
        $this->tokens = [];

        // Pre-scan raw regions so we can skip their inner content
        $rawRegions = $this->scanRawRegions();

        $this->scanHtmlText($rawRegions);

        $this->tokens[] = new Token(TokenType::Eof, '', $this->line, $this->column);

        return $this->tokens;
    }

    // ── HtmlText state ──────────────────────────────────────────────

    /**
     * Main scanning loop: reads text, detects `<` or `<?` transitions.
     *
     * @param array<array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}> $rawRegions
     */
    private function scanHtmlText(array $rawRegions): void
    {
        while ($this->pos < $this->length) {
            // Check if we're at the start of a raw region opening tag
            $rawRegion = $this->findRawRegionAt($rawRegions, $this->pos);
            if ($rawRegion !== null) {
                $this->emitRawRegion($rawRegion);
                continue;
            }

            // Check for PHP tags first (before `<` check for HTML tags)
            if ($this->lookingAt('<?=')) {
                $this->scanPhpOutput();
                continue;
            }

            if ($this->lookingAt('<?php') && !$this->isAlphanumAt($this->pos + 5)) {
                $this->scanPhpBlock();
                continue;
            }

            // Short open tag <?
            if ($this->lookingAt('<?')) {
                $this->scanPhpBlock();
                continue;
            }

            // Check for HTML comment
            if ($this->lookingAt('<!--')) {
                $this->scanComment();
                continue;
            }

            // Check for special tags (DOCTYPE, CDATA)
            if ($this->lookingAt('<!')) {
                $this->scanSpecialTag();
                continue;
            }

            // HTML tag starts with `<`
            if ($this->charAt($this->pos) === '<' && $this->isTagStart()) {
                $this->scanHtmlTag();
                continue;
            }

            // Otherwise accumulate text
            $this->scanText($rawRegions);
        }
    }

    /**
     * Accumulate plain text until we hit a tag boundary or PHP open.
     *
     * @param array<array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}> $rawRegions
     */
    private function scanText(array $rawRegions): void
    {
        $startLine = $this->line;
        $startCol = $this->column;
        $start = $this->pos;

        while ($this->pos < $this->length) {
            // Stop at raw region starts
            if ($this->findRawRegionAt($rawRegions, $this->pos) !== null) {
                break;
            }

            if ($this->lookingAt('<?')) {
                break;
            }

            $ch = $this->charAt($this->pos);
            if (
                $ch === '<'
                && ($this->isTagStart() || $this->lookingAt('<!--') || $this->lookingAt('<!'))
            ) {
                break;
            }

            $this->advance();
        }

        if ($this->pos > $start) {
            $this->tokens[] = new Token(
                TokenType::Text,
                substr($this->source, $start, $this->pos - $start),
                $startLine,
                $startCol,
            );
        }
    }

    // ── HtmlTag state ───────────────────────────────────────────────

    /**
     * Scan a complete HTML tag: `<tagName attrs... >` or `</tagName>`.
     */
    private function scanHtmlTag(): void
    {
        $tagOpenLine = $this->line;
        $tagOpenCol = $this->column;

        // Emit TagOpen `<`
        $this->tokens[] = new Token(TokenType::TagOpen, '<', $this->line, $this->column);
        $this->advance(); // skip `<`

        // Is this a closing tag?
        $isClosing = false;
        if ($this->charAt($this->pos) === '/') {
            $isClosing = true;
            $this->tokens[] = new Token(TokenType::Slash, '/', $this->line, $this->column);
            $this->advance();
        }

        // Read tag name
        $tagName = $this->readTagName();
        if ($tagName !== '') {
            $this->tokens[] = new Token(TokenType::TagName, $tagName, $tagOpenLine, $tagOpenCol + ($isClosing ? 2 : 1));
        }

        if ($isClosing) {
            // Closing tag: skip whitespace, expect `>`
            $this->skipWhitespace();
            if ($this->pos < $this->length && $this->charAt($this->pos) === '>') {
                $this->tokens[] = new Token(TokenType::TagClose, '>', $this->line, $this->column);
                $this->advance();
            }

            return;
        }

        // Read attributes until we hit `>`, `/>`, or end-of-file
        $this->scanAttributes();

        // Determine close type
        $selfClose = false;
        if ($this->lookingAt('/>')) {
            $selfClose = true;
            $this->tokens[] = new Token(TokenType::TagClose, '/>', $this->line, $this->column);
            $this->advance();
            $this->advance();
        } elseif ($this->pos < $this->length && $this->charAt($this->pos) === '>') {
            // Check if this is a known self-closing (void) element
            if ($tagName !== '' && HtmlTagHelper::isSelfClosing($tagName, $this->config->selfClosingTags)) {
                $selfClose = true;
            }

            $this->tokens[] = new Token(TokenType::TagClose, $selfClose ? '/>' : '>', $this->line, $this->column);
            $this->advance();
        }
    }

    /**
     * Read attributes inside an open tag.
     *
     * Handles: name, name="value", name='value', name=unquoted, name=PHP-expression
     */
    private function scanAttributes(): void
    {
        while ($this->pos < $this->length) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length) {
                break;
            }

            $ch = $this->charAt($this->pos);

            // End of tag
            if ($ch === '>' || $this->lookingAt('/>')) {
                break;
            }

            // Read attribute name
            $attrName = $this->readAttributeName();
            if ($attrName === '') {
                // Not a valid attribute start; advance to avoid infinite loop
                $this->advance();
                continue;
            }

            $attrCol = $this->column - strlen($attrName);
            $this->tokens[] = new Token(
                TokenType::AttributeName,
                $attrName,
                $this->line,
                $attrCol,
            );

            $this->skipWhitespace();

            // Check for `=`
            if ($this->pos < $this->length && $this->charAt($this->pos) === '=') {
                $this->tokens[] = new Token(TokenType::Equals, '=', $this->line, $this->column);
                $this->advance();
                $this->skipWhitespace();

                // Attribute value
                if ($this->pos < $this->length) {
                    $this->scanAttributeValue();
                }
            }

            // else: boolean attribute (no value)
        }
    }

    /**
     * Scan an attribute value: quoted, unquoted, or PHP expression.
     */
    private function scanAttributeValue(): void
    {
        $ch = $this->charAt($this->pos);

        // PHP expression directly as attribute value (unquoted)
        if ($this->lookingAt('<?=')) {
            $this->scanPhpOutput();

            return;
        }

        // Quoted value
        if ($ch === '"' || $ch === "'") {
            $this->scanQuotedAttributeValue($ch);

            return;
        }

        // Unquoted value - read until whitespace, >, or />
        $this->scanUnquotedAttributeValue();
    }

    /**
     * Scan a quoted attribute value, handling embedded PHP expressions.
     */
    private function scanQuotedAttributeValue(string $quote): void
    {
        $this->tokens[] = new Token(TokenType::QuoteOpen, $quote, $this->line, $this->column);
        $this->advance(); // skip the quote

        $textStart = $this->pos;
        $textLine = $this->line;
        $textCol = $this->column;

        while ($this->pos < $this->length) {
            // End of attribute value
            if ($this->charAt($this->pos) === $quote) {
                // Flush any accumulated text
                if ($this->pos > $textStart) {
                    $this->tokens[] = new Token(
                        TokenType::AttributeText,
                        substr($this->source, $textStart, $this->pos - $textStart),
                        $textLine,
                        $textCol,
                    );
                }

                $this->tokens[] = new Token(TokenType::QuoteClose, $quote, $this->line, $this->column);
                $this->advance();

                return;
            }

            // Embedded PHP expression
            if ($this->lookingAt('<?=')) {
                // Flush any accumulated text
                if ($this->pos > $textStart) {
                    $this->tokens[] = new Token(
                        TokenType::AttributeText,
                        substr($this->source, $textStart, $this->pos - $textStart),
                        $textLine,
                        $textCol,
                    );
                }

                $this->scanPhpOutput();

                // Reset text accumulator
                $textStart = $this->pos;
                $textLine = $this->line;
                $textCol = $this->column;
                continue;
            }

            // Handle escaped quotes
            if (
                $this->charAt($this->pos) === '\\'
                && ($this->pos + 1) < $this->length
                && $this->charAt($this->pos + 1) === $quote
            ) {
                $this->advance(); // skip backslash
                $this->advance(); // skip escaped quote
                continue;
            }

            $this->advance();
        }

        // Unclosed quote - flush remaining text
        if ($this->pos > $textStart) {
            $this->tokens[] = new Token(
                TokenType::AttributeText,
                substr($this->source, $textStart, $this->pos - $textStart),
                $textLine,
                $textCol,
            );
        }
    }

    /**
     * Scan an unquoted attribute value.
     */
    private function scanUnquotedAttributeValue(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        $startCol = $this->column;

        while ($this->pos < $this->length) {
            $ch = $this->charAt($this->pos);
            if (in_array($ch, [' ', "\t", "\n", "\r", '>'], true) || $this->lookingAt('/>')) {
                break;
            }

            $this->advance();
        }

        if ($this->pos > $start) {
            $this->tokens[] = new Token(
                TokenType::AttributeValueUnquoted,
                substr($this->source, $start, $this->pos - $start),
                $startLine,
                $startCol,
            );
        }
    }

    // ── PHP states ──────────────────────────────────────────────────

    /**
     * Scan a PHP short echo output block.
     */
    private function scanPhpOutput(): void
    {
        $this->tokens[] = new Token(TokenType::PhpOutputOpen, '<?=', $this->line, $this->column);
        $this->advance(); // <
        $this->advance(); // ?
        $this->advance(); // =

        $this->skipWhitespace();
        $exprStart = $this->pos;
        $exprLine = $this->line;
        $exprCol = $this->column;

        // Scan until PHP close tag
        while ($this->pos < $this->length) {
            if ($this->lookingAt('?>')) {
                break;
            }

            $this->advance();
        }

        // Trim trailing whitespace from expression
        $exprEnd = $this->pos;
        $expr = substr($this->source, $exprStart, $exprEnd - $exprStart);
        $expr = rtrim($expr);

        if ($expr !== '') {
            $this->tokens[] = new Token(TokenType::PhpExpression, $expr, $exprLine, $exprCol);
        }

        if ($this->lookingAt('?>')) {
            $this->tokens[] = new Token(TokenType::PhpClose, '?>', $this->line, $this->column);
            $this->advance();
            $this->advance();
        }
    }

    /**
     * Scan a PHP code block (full open tag).
     */
    private function scanPhpBlock(): void
    {
        $startLine = $this->line;
        $startCol = $this->column;

        // Determine open tag length
        if ($this->lookingAt('<?php')) {
            $openTag = '<?php';
        } else {
            $openTag = '<?';
        }

        $this->tokens[] = new Token(TokenType::PhpBlockOpen, $openTag, $startLine, $startCol);
        $openTagLen = strlen($openTag);
        for ($i = 0; $i < $openTagLen; $i++) {
            $this->advance();
        }

        $this->skipWhitespace();
        $codeStart = $this->pos;
        $codeLine = $this->line;
        $codeCol = $this->column;

        // Scan until close tag or end-of-file
        while ($this->pos < $this->length) {
            if ($this->lookingAt('?>')) {
                break;
            }

            $this->advance();
        }

        $code = substr($this->source, $codeStart, $this->pos - $codeStart);
        $code = rtrim($code);

        if ($code !== '') {
            $this->tokens[] = new Token(TokenType::PhpCode, $code, $codeLine, $codeCol);
        }

        if ($this->lookingAt('?>')) {
            $this->tokens[] = new Token(TokenType::PhpClose, '?>', $this->line, $this->column);
            $this->advance();
            $this->advance();
        }
    }

    // ── Comment / Special ───────────────────────────────────────────

    /**
     * Scan an HTML comment: <!-- ... -->
     */
    private function scanComment(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        $startCol = $this->column;

        // Skip past <!--
        for ($i = 0; $i < 4; $i++) {
            $this->advance();
        }

        // Find -->
        while ($this->pos < $this->length) {
            if ($this->lookingAt('-->')) {
                $this->advance();
                $this->advance();
                $this->advance();
                break;
            }

            $this->advance();
        }

        $this->tokens[] = new Token(
            TokenType::Comment,
            substr($this->source, $start, $this->pos - $start),
            $startLine,
            $startCol,
        );
    }

    /**
     * Scan a special tag (DOCTYPE, CDATA, etc.): <! ... >
     */
    private function scanSpecialTag(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        $startCol = $this->column;

        // CDATA sections
        if ($this->lookingAt('<![CDATA[')) {
            // Find ]]>
            while ($this->pos < $this->length) {
                if ($this->lookingAt(']]>')) {
                    $this->advance();
                    $this->advance();
                    $this->advance();
                    break;
                }

                $this->advance();
            }
        } else {
            // DOCTYPE or other <! tag - find closing >
            while ($this->pos < $this->length) {
                if ($this->charAt($this->pos) === '>') {
                    $this->advance();
                    break;
                }

                $this->advance();
            }
        }

        $this->tokens[] = new Token(
            TokenType::SpecialTag,
            substr($this->source, $start, $this->pos - $start),
            $startLine,
            $startCol,
        );
    }

    // ── Raw region pre-scanning ─────────────────────────────────────

    /**
     * Pre-scan the source for s:raw regions.
     *
     * Returns an array of raw region descriptors with byte offsets.
     * The lexer uses these to emit RawBody tokens and skip inner content.
     *
     * @return array<array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}>
     */
    private function scanRawRegions(): array
    {
        $rawAttribute = $this->prefixHelper->buildName('raw');
        if (!str_contains($this->source, $rawAttribute)) {
            return [];
        }

        $regions = [];
        $offset = 0;

        while (($region = $this->findNextRawRegion($offset, $rawAttribute)) !== null) {
            $regions[] = $region;
            $offset = $region['closeEnd'];
        }

        return $regions;
    }

    /**
     * Find the next raw region starting from `$offset`.
     *
     * @return array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}|null
     */
    private function findNextRawRegion(int $offset, string $rawAttribute): ?array
    {
        while (($tagStart = strpos($this->source, '<', $offset)) !== false) {
            $tag = $this->extractSimpleTag($tagStart);
            if ($tag === null || $tag['type'] !== 'open') {
                $offset = $tagStart + 1;
                continue;
            }

            $offset = $tag['end'];

            if ($tag['selfClosing']) {
                continue;
            }

            if (!$this->tagHasRawAttribute($tag['raw'], $rawAttribute)) {
                continue;
            }

            $closeStart = $this->findMatchingCloseTag($tag['name'], $tag['end']);
            if ($closeStart === null) {
                continue;
            }

            $closeTag = $this->extractSimpleTag($closeStart);
            if ($closeTag === null) {
                continue;
            }

            if ($closeTag['type'] !== 'close') {
                continue;
            }

            return [
                'openStart' => $tag['start'],
                'openEnd' => $tag['end'],
                'innerStart' => $tag['end'],
                'innerEnd' => $closeStart,
                'closeStart' => $closeStart,
                'closeEnd' => $closeTag['end'],
                'tagName' => $tag['name'],
            ];
        }

        return null;
    }

    /**
     * Emit tokens for a raw region: opening tag tokens, RawBody, closing tag tokens.
     *
     * @param array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string} $region
     */
    private function emitRawRegion(array $region): void
    {
        // Parse the opening tag itself (emit tag tokens)
        $openTagSource = substr($this->source, $region['openStart'], $region['openEnd'] - $region['openStart']);
        $this->emitRawOpeningTag($openTagSource, $region['openStart']);

        // Emit raw body content
        $innerContent = substr($this->source, $region['innerStart'], $region['innerEnd'] - $region['innerStart']);
        if ($innerContent !== '') {
            [$innerLine, $innerCol] = $this->lineColumnAt($region['innerStart']);
            $this->tokens[] = new Token(TokenType::RawBody, $innerContent, $innerLine, $innerCol);
        }

        // Emit closing tag tokens
        $this->emitSimpleClosingTag($region['tagName'], $region['closeStart']);

        // Advance position past this entire region
        $this->advanceTo($region['closeEnd']);
    }

    /**
     * Emit tokens for the opening tag of a raw region, stripping the s:raw attribute.
     */
    private function emitRawOpeningTag(string $tagSource, int $absoluteOffset): void
    {
        $rawAttribute = $this->prefixHelper->buildName('raw');
        [$tagLine, $tagCol] = $this->lineColumnAt($absoluteOffset);

        // <
        $this->tokens[] = new Token(TokenType::TagOpen, '<', $tagLine, $tagCol);

        // Parse tag name from the source
        $nameStart = 1; // after <
        $nameEnd = $nameStart;
        $tagLen = strlen($tagSource);
        while (
            $nameEnd < $tagLen
            && !$this->isWhitespaceChar($tagSource[$nameEnd])
            && $tagSource[$nameEnd] !== '>'
            && $tagSource[$nameEnd] !== '/'
        ) {
            $nameEnd++;
        }

        $tagName = substr($tagSource, $nameStart, $nameEnd - $nameStart);

        $this->tokens[] = new Token(TokenType::TagName, $tagName, $tagLine, $tagCol + 1);

        // Parse attributes from the raw tag source (skip the raw attribute)
        $attrRegion = substr($tagSource, $nameEnd);
        $attrRegion = rtrim($attrRegion, " \t\n\r/>");
        $this->emitAttributesFromString($attrRegion, $rawAttribute, $tagLine);

        // Determine self-closing
        $trimmed = rtrim($tagSource);
        $closeStr = str_ends_with($trimmed, '/>') ? '/>' : '>';
        $closeCol = $tagCol + strlen($tagSource) - strlen($closeStr);
        $this->tokens[] = new Token(
            TokenType::TagClose,
            $closeStr,
            $tagLine,
            $closeCol,
        );
    }

    /**
     * Parse and emit attribute tokens from a raw string, skipping a specific attribute name.
     */
    private function emitAttributesFromString(string $attrString, string $skipAttribute, int $baseLine): void
    {
        $pos = 0;
        $len = strlen($attrString);

        while ($pos < $len) {
            // Skip whitespace
            while ($pos < $len && $this->isWhitespaceChar($attrString[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                break;
            }

            // Read attribute name
            $nameStart = $pos;
            while (
                $pos < $len
                && !$this->isWhitespaceChar($attrString[$pos])
                && $attrString[$pos] !== '='
                && $attrString[$pos] !== '>'
                && $attrString[$pos] !== '/'
            ) {
                $pos++;
            }

            $name = substr($attrString, $nameStart, $pos - $nameStart);
            if ($name === '' || $name === $skipAttribute) {
                // Skip whitespace, `=`, and optional value for the skipped attribute
                while ($pos < $len && $this->isWhitespaceChar($attrString[$pos])) {
                    $pos++;
                }

                if ($pos < $len && $attrString[$pos] === '=') {
                    $pos++;
                    while ($pos < $len && $this->isWhitespaceChar($attrString[$pos])) {
                        $pos++;
                    }

                    if ($pos < $len && ($attrString[$pos] === '"' || $attrString[$pos] === "'")) {
                        $q = $attrString[$pos];
                        $pos++;
                        while ($pos < $len && $attrString[$pos] !== $q) {
                            $pos++;
                        }

                        if ($pos < $len) {
                            $pos++;
                        }
                    } else {
                        while ($pos < $len && !$this->isWhitespaceChar($attrString[$pos])) {
                            $pos++;
                        }
                    }
                }

                continue;
            }

            $this->tokens[] = new Token(TokenType::AttributeName, $name, $baseLine, 0);

            // Skip whitespace
            while ($pos < $len && $this->isWhitespaceChar($attrString[$pos])) {
                $pos++;
            }

            // Check for =
            if ($pos < $len && $attrString[$pos] === '=') {
                $this->tokens[] = new Token(TokenType::Equals, '=', $baseLine, 0);
                $pos++;

                while ($pos < $len && $this->isWhitespaceChar($attrString[$pos])) {
                    $pos++;
                }

                // Read value
                if ($pos < $len && ($attrString[$pos] === '"' || $attrString[$pos] === "'")) {
                    $q = $attrString[$pos];
                    $this->tokens[] = new Token(TokenType::QuoteOpen, $q, $baseLine, 0);
                    $pos++;
                    $valStart = $pos;
                    while ($pos < $len && $attrString[$pos] !== $q) {
                        if ($attrString[$pos] === '\\' && ($pos + 1) < $len && $attrString[$pos + 1] === $q) {
                            $pos += 2;
                            continue;
                        }

                        $pos++;
                    }

                    $val = substr($attrString, $valStart, $pos - $valStart);
                    if ($val !== '') {
                        $this->tokens[] = new Token(TokenType::AttributeText, $val, $baseLine, 0);
                    }

                    $this->tokens[] = new Token(TokenType::QuoteClose, $q, $baseLine, 0);
                    if ($pos < $len) {
                        $pos++;
                    }
                } else {
                    $valStart = $pos;
                    while ($pos < $len && !$this->isWhitespaceChar($attrString[$pos])) {
                        $pos++;
                    }

                    $val = substr($attrString, $valStart, $pos - $valStart);
                    if ($val !== '') {
                        $this->tokens[] = new Token(TokenType::AttributeValueUnquoted, $val, $baseLine, 0);
                    }
                }
            }
        }
    }

    /**
     * Emit tokens for a simple closing tag.
     */
    private function emitSimpleClosingTag(string $tagName, int $start): void
    {
        [$closeLine, $closeCol] = $this->lineColumnAt($start);

        $this->tokens[] = new Token(TokenType::TagOpen, '<', $closeLine, $closeCol);
        $this->tokens[] = new Token(TokenType::Slash, '/', $closeLine, $closeCol + 1);
        $this->tokens[] = new Token(TokenType::TagName, $tagName, $closeLine, $closeCol + 2);
        $this->tokens[] = new Token(TokenType::TagClose, '>', $closeLine, $closeCol + 2 + strlen($tagName));
    }

    // ── Low-level helpers ───────────────────────────────────────────

    /**
     * Extract a simple tag descriptor at the given offset (for raw region scanning).
     *
     * @return array{type: 'open'|'close', name: string, start: int, end: int, selfClosing: bool, raw: string}|null
     */
    private function extractSimpleTag(int $start): ?array
    {
        if (!isset($this->source[$start]) || $this->source[$start] !== '<') {
            return null;
        }

        $next = $this->source[$start + 1] ?? null;
        if (in_array($next, [null, '!', '?'], true)) {
            return null;
        }

        if ($next === '/') {
            $nameStart = $start + 2;
            $nameEnd = $this->readSimpleTagNameEnd($nameStart);
            if ($nameEnd === $nameStart) {
                return null;
            }

            $end = $this->findSimpleTagEnd($nameEnd);
            if ($end === null) {
                return null;
            }

            return [
                'type' => 'close',
                'name' => substr($this->source, $nameStart, $nameEnd - $nameStart),
                'start' => $start,
                'end' => $end,
                'selfClosing' => false,
                'raw' => substr($this->source, $start, $end - $start),
            ];
        }

        if (!ctype_alpha($next)) {
            return null;
        }

        $nameStart = $start + 1;
        $nameEnd = $this->readSimpleTagNameEnd($nameStart);
        if ($nameEnd === $nameStart) {
            return null;
        }

        $end = $this->findSimpleTagEnd($nameEnd);
        if ($end === null) {
            return null;
        }

        $name = substr($this->source, $nameStart, $nameEnd - $nameStart);
        $rawTag = substr($this->source, $start, $end - $start);
        $selfClosing = str_ends_with(rtrim($rawTag), '/>')
            || HtmlTagHelper::isSelfClosing($name, $this->config->selfClosingTags);

        return [
            'type' => 'open',
            'name' => $name,
            'start' => $start,
            'end' => $end,
            'selfClosing' => $selfClosing,
            'raw' => $rawTag,
        ];
    }

    /**
     * Read past a tag name starting at `$pos` in `$this->source`.
     */
    private function readSimpleTagNameEnd(int $pos): int
    {
        while ($pos < $this->length) {
            $ch = $this->source[$pos];
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === ':' || $ch === '.') {
                $pos++;
            } else {
                break;
            }
        }

        return $pos;
    }

    /**
     * Find the `>` that closes a tag, respecting quoted attribute values.
     */
    private function findSimpleTagEnd(int $pos): ?int
    {
        while ($pos < $this->length) {
            $ch = $this->source[$pos];
            if ($ch === '>') {
                return $pos + 1;
            }

            if ($ch === '"' || $ch === "'") {
                $pos++;
                while ($pos < $this->length && $this->source[$pos] !== $ch) {
                    $pos++;
                }
            }

            $pos++;
        }

        return null;
    }

    /**
     * Find a matching close tag for the given tag name, starting from `$offset`.
     */
    private function findMatchingCloseTag(string $tagName, int $offset): ?int
    {
        $depth = 1;

        while (($tagStart = strpos($this->source, '<', $offset)) !== false) {
            $tag = $this->extractSimpleTag($tagStart);
            if ($tag === null) {
                $offset = $tagStart + 1;
                continue;
            }

            $offset = $tag['end'];

            if (strcasecmp($tag['name'], $tagName) !== 0) {
                continue;
            }

            if ($tag['type'] === 'close') {
                $depth--;
                if ($depth === 0) {
                    return $tag['start'];
                }

                continue;
            }

            if (!$tag['selfClosing']) {
                $depth++;
            }
        }

        return null;
    }

    /**
     * Check if a raw tag string contains the raw attribute.
     */
    private function tagHasRawAttribute(string $tag, string $rawAttribute): bool
    {
        $pattern =
            '/(?:\s|^)' .
            preg_quote($rawAttribute, '/') .
            '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/';

        return preg_match($pattern, $tag) === 1;
    }

    /**
     * Find a raw region that starts exactly at `$pos`.
     *
     * @param array<array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}> $regions
     * @return array{openStart: int, openEnd: int, innerStart: int, innerEnd: int, closeStart: int, closeEnd: int, tagName: string}|null
     */
    private function findRawRegionAt(array $regions, int $pos): ?array
    {
        foreach ($regions as $region) {
            if ($region['openStart'] === $pos) {
                return $region;
            }
        }

        return null;
    }

    /**
     * Read a tag name from the current position.
     */
    private function readTagName(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->charAt($this->pos);
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === ':' || $ch === '.') {
                $this->advance();
            } else {
                break;
            }
        }

        return substr($this->source, $start, $this->pos - $start);
    }

    /**
     * Read an attribute name from the current position.
     */
    private function readAttributeName(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->charAt($this->pos);
            // Attribute names can contain: alphanumeric, -, _, :, .
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === ':' || $ch === '.' || $ch === '@') {
                $this->advance();
            } else {
                break;
            }
        }

        return substr($this->source, $start, $this->pos - $start);
    }

    /**
     * Check if position `$pos` looks like the start of an HTML tag.
     */
    private function isTagStart(): bool
    {
        if ($this->pos + 1 >= $this->length) {
            return false;
        }

        $next = $this->charAt($this->pos + 1);

        return ctype_alpha($next) || $next === '/';
    }

    /**
     * Check whether source at current position starts with the given string.
     */
    private function lookingAt(string $str): bool
    {
        return substr_compare($this->source, $str, $this->pos, strlen($str)) === 0;
    }

    /**
     * Get a single character from the source.
     */
    private function charAt(int $pos): string
    {
        return $this->source[$pos] ?? "\0";
    }

    /**
     * Check if byte at position is alphanumeric.
     */
    private function isAlphanumAt(int $pos): bool
    {
        return $pos < $this->length && ctype_alnum($this->source[$pos]);
    }

    /**
     * Advance position by one character, tracking line/column.
     */
    private function advance(): void
    {
        if ($this->pos < $this->length) {
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
                $this->column = 1;
            } else {
                $this->column++;
            }

            $this->pos++;
        }
    }

    /**
     * Advance position to a specific absolute offset, updating line/column.
     */
    private function advanceTo(int $target): void
    {
        while ($this->pos < $target && $this->pos < $this->length) {
            $this->advance();
        }
    }

    /**
     * Skip whitespace characters.
     */
    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && $this->isWhitespaceChar($this->source[$this->pos])) {
            $this->advance();
        }
    }

    /**
     * Check if a character is whitespace.
     */
    private function isWhitespaceChar(string $ch): bool
    {
        return in_array($ch, [' ', "\t", "\n", "\r"], true);
    }

    /**
     * Calculate 1-based line and column for an absolute byte offset.
     *
     * @return array{0: int, 1: int} [line, column]
     */
    private function lineColumnAt(int $offset): array
    {
        $before = substr($this->source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;

        return [$line, $column];
    }
}
