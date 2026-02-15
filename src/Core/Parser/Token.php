<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

use PhpToken;

/**
 * Extended PhpToken with helper methods for template parsing
 *
 * Provides convenience methods for identifying token types and extracting content
 * relevant to template parsing.
 */
final class Token extends PhpToken
{
    public const T_RAW_BODY = -10001;

    /**
     * Check if this is an HTML/text token outside PHP tags
     */
    public function isHtml(): bool
    {
        return $this->id === T_INLINE_HTML;
    }

    /**
     * Check if this is a PHP output token (<?= or echo)
     */
    public function isOutput(): bool
    {
        return $this->id === T_OPEN_TAG_WITH_ECHO;
    }

    /**
     * Check if this is a PHP open tag
     */
    public function isOpenTag(): bool
    {
        return $this->id === T_OPEN_TAG || $this->id === T_OPEN_TAG_WITH_ECHO;
    }

    /**
     * Check if this is a synthetic raw-body token.
     */
    public function isRawBody(): bool
    {
        return $this->id === self::T_RAW_BODY;
    }

    /**
     * Check if this is a PHP close tag
     */
    public function isCloseTag(): bool
    {
        return $this->id === T_CLOSE_TAG;
    }

    /**
     * Check if this token can be ignored during parsing
     */
    public function canIgnore(): bool
    {
        return $this->isIgnorable();
    }

    /**
     * Get the text content, trimmed
     */
    public function content(): string
    {
        return $this->text;
    }

    /**
     * Check if this token contains HTML-like content (has < or >)
     */
    public function containsHtml(): bool
    {
        return $this->isHtml() && (str_contains($this->text, '<') || str_contains($this->text, '>'));
    }

    /**
     * Tokenize source into Token instances
     *
     * @param string $source Template source code
     * @return array<\Sugar\Core\Parser\Token>
     */
    public static function tokenize(string $source, int $flags = 0): array
    {
        /** @var array<\PhpToken> $tokens */
        $tokens = parent::tokenize($source, $flags);

        // Convert PhpToken instances to Token instances
        return array_map(
            fn(PhpToken $token): Token => new self($token->id, $token->text, $token->line, $token->pos),
            $tokens,
        );
    }
}
