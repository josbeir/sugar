<?php
declare(strict_types=1);

namespace Sugar\Core\Ast;

/**
 * Value object for HTML attribute values.
 *
 * Normalizes attributes to one of four shapes:
 * - boolean (presence-only attributes like disabled)
 * - static string value
 * - output node (dynamic expression)
 * - mixed parts (string/output interleaving)
 */
final readonly class AttributeValue
{
    /**
     * @param array<int, string|\Sugar\Core\Ast\OutputNode>|null $parts
     */
    private function __construct(
        public ?string $static,
        public ?OutputNode $output,
        public ?array $parts,
        public bool $isBoolean,
    ) {
    }

    /**
     * Use for presence-only attributes like disabled or required.
     */
    public static function boolean(): self
    {
        return new self(null, null, null, true);
    }

    /**
     * Use for literal attribute values parsed from markup.
     */
    public static function static(string $value): self
    {
        return new self($value, null, null, false);
    }

    /**
     * Use for a single dynamic expression in an attribute.
     */
    public static function output(OutputNode $value): self
    {
        return new self(null, $value, null, false);
    }

    /**
     * Use for attributes composed of interleaved strings and output nodes.
     *
     * @param array<int, string|\Sugar\Core\Ast\OutputNode> $parts
     */
    public static function parts(array $parts): self
    {
        return new self(null, null, $parts, false);
    }

    /**
     * Normalize legacy attribute value shapes into an AttributeValue.
     *
     * @param \Sugar\Core\Ast\OutputNode|array<int, string|\Sugar\Core\Ast\OutputNode>|string|null $value
     */
    public static function from(string|OutputNode|array|null $value): self
    {
        if ($value === null) {
            return self::boolean();
        }

        if ($value instanceof OutputNode) {
            return self::output($value);
        }

        if (is_array($value)) {
            return self::parts($value);
        }

        return self::static($value);
    }

    /**
     * True when the attribute is presence-only (no value).
     */
    public function isBoolean(): bool
    {
        return $this->isBoolean;
    }

    /**
     * True when the attribute is a literal string.
     */
    public function isStatic(): bool
    {
        return $this->static !== null;
    }

    /**
     * True when the attribute is a single output expression.
     */
    public function isOutput(): bool
    {
        return $this->output instanceof OutputNode;
    }

    /**
     * True when the attribute is a mixed parts list.
     */
    public function isParts(): bool
    {
        return $this->parts !== null;
    }

    /**
     * Return a normalized parts list or null for boolean attributes.
     *
     * @return array<int, string|\Sugar\Core\Ast\OutputNode>|null
     */
    public function toParts(): ?array
    {
        if ($this->isBoolean) {
            return null;
        }

        if ($this->parts !== null) {
            return $this->parts;
        }

        if ($this->output instanceof OutputNode) {
            return [$this->output];
        }

        return [$this->static ?? ''];
    }
}
