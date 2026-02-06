<?php
declare(strict_types=1);

namespace Sugar\Tests;

use Sugar\Tests\Constraint\ContainsPhpCode;
use Sugar\Tests\Constraint\HasValidPhpSyntax;
use Sugar\Tests\Constraint\MatchesAstStructure;

/**
 * Trait providing custom PHPUnit constraints for Sugar tests
 */
trait CustomConstraintsTrait
{
    /**
     * Create constraint that asserts PHP code is present
     */
    public static function containsPhpCode(string $code): ContainsPhpCode
    {
        return new ContainsPhpCode($code);
    }

    /**
     * Create constraint that asserts PHP syntax is valid
     */
    public static function hasValidPhpSyntax(): HasValidPhpSyntax
    {
        return new HasValidPhpSyntax();
    }

    /**
     * Create constraint that asserts AST structure matches expected
     *
     * @param array<string|array{type: string, properties?: array<string, mixed>}> $structure
     */
    public static function matchesAstStructure(array $structure): MatchesAstStructure
    {
        return new MatchesAstStructure($structure);
    }
}
