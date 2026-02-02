<?php
declare(strict_types=1);

namespace Sugar\Parser;

use PhpToken;

/**
 * Extended PhpToken with Sugar-specific helper methods
 *
 * Provides convenience methods for identifying token types and extracting content
 * relevant to Sugar template parsing.
 */
final class SugarToken extends PhpToken
{
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
     * Tokenize source into SugarToken instances
     *
     * @param string $source Template source code
     * @return array<\Sugar\Parser\SugarToken>
     */
    public static function tokenize(string $source, int $flags = 0): array
    {
        /** @var array<\PhpToken> $tokens */
        $tokens = parent::tokenize($source, $flags);

        // Convert PhpToken instances to SugarToken instances
        return array_map(
            fn(PhpToken $token): SugarToken => new self($token->id, $token->text, $token->line, $token->pos),
            $tokens,
        );
    }
}
