<?php
declare(strict_types=1);

namespace Sugar\Enum;

/**
 * Template inheritance attribute names
 *
 * These attributes are processed by TemplateInheritancePass before
 * DirectiveExtractionPass runs.
 */
enum InheritanceAttribute: string
{
    case BLOCK = 's:block';
    case EXTENDS = 's:extends';
    case INCLUDE = 's:include';
    case WITH = 's:with';

    /**
     * Check if a given attribute name is an inheritance attribute
     */
    public static function isInheritanceAttribute(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }

    /**
     * Get all inheritance attribute names as array
     *
     * @return array<string>
     */
    public static function names(): array
    {
        return array_map(fn(self $attr) => $attr->value, self::cases());
    }
}
