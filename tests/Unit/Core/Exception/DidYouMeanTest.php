<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Exception;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\Helper\DidYouMean;

/**
 * Test DidYouMean suggestion system using Levenshtein distance
 */
final class DidYouMeanTest extends TestCase
{
    /**
     * @var array<string>
     */
    private array $validDirectives = [
        'if',
        'else',
        'elseif',
        'foreach',
        'for',
        'while',
        'switch',
        'case',
        'slot',
        'component',
    ];

    public function testSuggestsCloseMatch(): void
    {
        $suggestion = DidYouMean::suggest('forech', $this->validDirectives);

        $this->assertSame('foreach', $suggestion);
    }

    public function testSuggestsAnotherCloseMatch(): void
    {
        $suggestion = DidYouMean::suggest('iff', $this->validDirectives);

        $this->assertSame('if', $suggestion);
    }

    public function testSuggestsComponentForComponnt(): void
    {
        $suggestion = DidYouMean::suggest('componnt', $this->validDirectives);

        $this->assertSame('component', $suggestion);
    }

    public function testReturnsNullForExactMatch(): void
    {
        $suggestion = DidYouMean::suggest('foreach', $this->validDirectives);

        // Exact match means no suggestion needed
        $this->assertNull($suggestion);
    }

    public function testReturnsNullWhenDistanceTooLarge(): void
    {
        $suggestion = DidYouMean::suggest('completely_wrong', $this->validDirectives);

        // Distance > 2, no suggestion
        $this->assertNull($suggestion);
    }

    public function testReturnsNullForEmptyInput(): void
    {
        $suggestion = DidYouMean::suggest('', $this->validDirectives);

        $this->assertNull($suggestion);
    }

    public function testReturnsNullForEmptyCandidates(): void
    {
        $suggestion = DidYouMean::suggest('foreach', []);

        $this->assertNull($suggestion);
    }

    public function testSuggestsClosestMatch(): void
    {
        // When multiple matches exist, suggest the closest
        $suggestion = DidYouMean::suggest('els', ['if', 'else', 'elseif']);

        $this->assertSame('else', $suggestion); // Distance 1 vs 'elseif' distance 3
    }

    public function testHandlesCaseInsensitive(): void
    {
        $suggestion = DidYouMean::suggest('FORECH', ['foreach', 'for']);

        $this->assertSame('foreach', $suggestion);
    }

    public function testDistance1IsSuggested(): void
    {
        // Single character typo
        $suggestion = DidYouMean::suggest('swich', ['switch', 'slot']);

        $this->assertSame('switch', $suggestion);
    }

    public function testDistance2IsSuggested(): void
    {
        // Two character difference
        $suggestion = DidYouMean::suggest('swotch', ['switch']);

        $this->assertSame('switch', $suggestion); // Distance is 2 (i->o, missing i)
    }

    public function testDistance3IsNotSuggested(): void
    {
        // Three character difference - too far
        // "swoaatch" vs "switch": insert 'o', insert 'a', replace 'a' with 'i' = distance 3
        $suggestion = DidYouMean::suggest('swoaatch', ['switch']);

        $this->assertNull($suggestion);
    }

    public function testSuggestsFromMultipleCandidates(): void
    {
        $candidates = [
            'data-bind',
            'data-model',
            'data-value',
            'data-show',
            'data-hide',
        ];

        $suggestion = DidYouMean::suggest('data-bnd', $candidates);

        $this->assertSame('data-bind', $suggestion);
    }

    public function testConfigurableMaxDistance(): void
    {
        // "foreeach" -> "foreach" is actually distance 1, so let's use a different example
        // "foreeeach" -> "foreach" is distance 2 (remove two 'e's)
        $suggestion = DidYouMean::suggest('foreeeach', ['foreach'], maxDistance: 3);

        $this->assertSame('foreach', $suggestion); // Distance 2 allowed with maxDistance 3
    }

    public function testDefaultMaxDistanceIsTwo(): void
    {
        // "foreeeach" -> "foreach" distance is 2, so let's use distance 3
        // "foreeeeach" -> "foreach" is distance 3 (remove three 'e's)
        $suggestion = DidYouMean::suggest('foreeeeach', ['foreach']);

        $this->assertNull($suggestion); // Distance 3, default max is 2
    }
}
