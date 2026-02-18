<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Compiler\PhpImportRegistry;

/**
 * Tests for PhpImportRegistry import canonicalization and deduplication.
 */
final class PhpImportRegistryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // canonicalize() — static, pure transformation
    // -------------------------------------------------------------------------

    public function testCanonicalizeSimpleClassImport(): void
    {
        $result = PhpImportRegistry::canonicalize('use DateTimeImmutable;');

        $this->assertSame(['use DateTimeImmutable;'], $result);
    }

    public function testCanonicalizeFunctionImport(): void
    {
        $result = PhpImportRegistry::canonicalize('use function strlen;');

        $this->assertSame(['use function strlen;'], $result);
    }

    public function testCanonicalizeConstImport(): void
    {
        $result = PhpImportRegistry::canonicalize('use const PHP_EOL;');

        $this->assertSame(['use const PHP_EOL;'], $result);
    }

    public function testCanonicalizeImportWithAlias(): void
    {
        $result = PhpImportRegistry::canonicalize('use DateTimeImmutable as Clock;');

        $this->assertSame(['use DateTimeImmutable as Clock;'], $result);
    }

    public function testCanonicalizeAddsSemicolonWhenMissing(): void
    {
        $result = PhpImportRegistry::canonicalize('use DateTimeImmutable');

        $this->assertSame(['use DateTimeImmutable;'], $result);
    }

    public function testCanonicalizeExpandsGroupedClassImports(): void
    {
        $result = PhpImportRegistry::canonicalize('use Ns\\{A, B, C};');

        $this->assertSame(['use Ns\\A;', 'use Ns\\B;', 'use Ns\\C;'], $result);
    }

    public function testCanonicalizeExpandsGroupedFunctionImports(): void
    {
        $result = PhpImportRegistry::canonicalize('use function Sugar\\Core\\Runtime\\{raw, json};');

        $this->assertSame(
            ['use function Sugar\\Core\\Runtime\\raw;', 'use function Sugar\\Core\\Runtime\\json;'],
            $result,
        );
    }

    public function testCanonicalizeExpandsGroupedImportsWithAliases(): void
    {
        $result = PhpImportRegistry::canonicalize('use Ns\\{A as Alias, B};');

        $this->assertSame(['use Ns\\A as Alias;', 'use Ns\\B;'], $result);
    }

    public function testCanonicalizeOmitsRedundantDefaultAlias(): void
    {
        // 'Clock' is not the last segment of DateTimeImmutable, so alias stays
        $result = PhpImportRegistry::canonicalize('use DateTimeImmutable as DateTimeImmutable;');

        // alias == default alias → omitted
        $this->assertSame(['use DateTimeImmutable;'], $result);
    }

    // -------------------------------------------------------------------------
    // add() / all() — instance, stateful deduplication
    // -------------------------------------------------------------------------

    public function testAddAndRetrieveSimpleImport(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use DateTimeImmutable;');

        $this->assertSame(['use DateTimeImmutable;'], $registry->all());
    }

    public function testAddDeduplicatesIdenticalStatements(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use DateTimeImmutable;');
        $registry->add('use DateTimeImmutable;');

        $this->assertSame(['use DateTimeImmutable;'], $registry->all());
    }

    public function testAddDeduplicatesEquivalentStatementsWithDifferentWhitespace(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use DateTimeImmutable as Clock;');
        $registry->add("use   DateTimeImmutable\n/* duplicate */ as Clock");

        // First-wins: original statement is kept when alias key matches
        $this->assertSame(['use DateTimeImmutable as Clock;'], $registry->all());
    }

    public function testAddDeduplicatesGroupedAndSingleFunctionImports(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use function Sugar\\Core\\Runtime\\{raw, json};');
        $registry->add('use function Sugar\\Core\\Runtime\\json;');

        $this->assertSame(
            [
                'use function Sugar\\Core\\Runtime\\raw;',
                'use function Sugar\\Core\\Runtime\\json;',
            ],
            $registry->all(),
        );
    }

    public function testAddFirstWinsOnAliasConflict(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use Foo\\Bar;');
        $registry->add('use Baz\\Bar;'); // 'Bar' alias already taken — ignored

        $this->assertSame(['use Foo\\Bar;'], $registry->all());
    }

    public function testAddPreservesInsertionOrder(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use C\\D;');
        $registry->add('use A\\B;');

        $this->assertSame(['use C\\D;', 'use A\\B;'], $registry->all());
    }

    public function testAllReturnsEmptyArrayWhenNothingAdded(): void
    {
        $registry = new PhpImportRegistry();

        $this->assertSame([], $registry->all());
    }

    public function testMultipleRegistriesAreIndependent(): void
    {
        $a = new PhpImportRegistry();
        $a->add('use Foo\\Bar;');

        $b = new PhpImportRegistry();
        $b->add('use Baz\\Qux;');

        $this->assertSame(['use Foo\\Bar;'], $a->all());
        $this->assertSame(['use Baz\\Qux;'], $b->all());
    }

    // -------------------------------------------------------------------------
    // canonicalize() edge paths
    // -------------------------------------------------------------------------

    public function testCanonicalizeFallsBackToRawWhenRegexDoesNotMatch(): void
    {
        // An empty body after the 'use' keyword makes canonicalize return the cleaned string as-is
        // because the regex body capture turns up empty.
        // We craft a statement that matches outer regex but has empty body: "use  ;"
        $result = PhpImportRegistry::canonicalize('use  ;');

        // The regex captures body as '' → returns [$clean]
        $this->assertCount(1, $result);
        $this->assertStringContainsString('use', $result[0]);
    }

    public function testCanonicalizeGroupedImportWithEmptyInnerBodyFallsBack(): void
    {
        // Grouped syntax with empty braces: "use Ns\\{};" — inner is '' after trim
        $result = PhpImportRegistry::canonicalize('use Ns\\{};');

        // Falls back to the original cleaned statement
        $this->assertSame(['use Ns\\{};'], $result);
    }

    public function testCanonicalizeGroupedImportDropsEmptyClauses(): void
    {
        // Trailing comma produces an empty clause that parseClause() returns null for
        $result = PhpImportRegistry::canonicalize('use Ns\\{A, };');

        $this->assertSame(['use Ns\\A;'], $result);
    }

    public function testCanonicalizeCommaSeparatedImports(): void
    {
        $result = PhpImportRegistry::canonicalize('use Foo\\A, Foo\\B;');

        $this->assertSame(['use Foo\\A;', 'use Foo\\B;'], $result);
    }

    public function testCanonicalizeCommaSeparatedImportsDropsEmptyClauses(): void
    {
        // Trailing comma produces empty clause
        $result = PhpImportRegistry::canonicalize('use Foo\\A, ;');

        $this->assertSame(['use Foo\\A;'], $result);
    }

    public function testCanonicalizeGroupedWithNoPrefixExpandsCorrectly(): void
    {
        // No prefix before '{': use {A, B}; — prefix === '' path
        $result = PhpImportRegistry::canonicalize('use {A, B};');

        $this->assertSame(['use A;', 'use B;'], $result);
    }

    // -------------------------------------------------------------------------
    // add() edge paths
    // -------------------------------------------------------------------------

    public function testAddHandlesUnparseableCanonicalStatementWithoutAlias(): void
    {
        // When canonicalize() returns something that parseStatement() cannot parse
        // (e.g. "use  ;" with empty body), add() falls through to the raw-key path.
        $registry = new PhpImportRegistry();
        $registry->add('use  ;');

        // Should have one entry (the raw statement), not throw
        $this->assertCount(1, $registry->all());
    }

    public function testAddDoesNotDuplicateUnparseableStatements(): void
    {
        $registry = new PhpImportRegistry();
        $registry->add('use  ;');
        $registry->add('use  ;');

        $this->assertCount(1, $registry->all());
    }

    public function testCanonicalizeGroupedImportDropsClauseWithOnlyAlias(): void
    {
        // A clause that is purely whitespace (between two commas) results in an
        // empty string after trim, so parseClause returns null and it is skipped.
        $result = PhpImportRegistry::canonicalize('use Ns\\{A, , B};');

        // The empty clause between the two commas is dropped silently
        $this->assertSame(['use Ns\\A;', 'use Ns\\B;'], $result);
    }

    public function testAddHandlesStatementThatFailsOuterRegex(): void
    {
        // 'use;' (no whitespace between 'use' and ';') fails both the canonicalize
        // regex and the parseStatement regex — exercises the regex-fails path in
        // parseStatement and the malformed-statement fallback in add().
        $registry = new PhpImportRegistry();
        $registry->add('use;');

        $this->assertCount(1, $registry->all());
    }
}
