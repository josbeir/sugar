<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Sugar\Enum\InheritanceAttribute;

final class InheritanceAttributeTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('s:block', InheritanceAttribute::BLOCK->value);
        $this->assertSame('s:extends', InheritanceAttribute::EXTENDS->value);
        $this->assertSame('s:include', InheritanceAttribute::INCLUDE->value);
        $this->assertSame('s:with', InheritanceAttribute::WITH->value);
    }

    public function testIsInheritanceAttribute(): void
    {
        $this->assertTrue(InheritanceAttribute::isInheritanceAttribute('s:block'));
        $this->assertTrue(InheritanceAttribute::isInheritanceAttribute('s:extends'));
        $this->assertTrue(InheritanceAttribute::isInheritanceAttribute('s:include'));
        $this->assertTrue(InheritanceAttribute::isInheritanceAttribute('s:with'));
    }

    public function testIsInheritanceAttributeReturnsFalse(): void
    {
        $this->assertFalse(InheritanceAttribute::isInheritanceAttribute('s:if'));
        $this->assertFalse(InheritanceAttribute::isInheritanceAttribute('s:foreach'));
        $this->assertFalse(InheritanceAttribute::isInheritanceAttribute('class'));
        $this->assertFalse(InheritanceAttribute::isInheritanceAttribute('unknown'));
    }

    public function testNames(): void
    {
        $names = InheritanceAttribute::names();

        $this->assertCount(4, $names);
        $this->assertContains('s:block', $names);
        $this->assertContains('s:extends', $names);
        $this->assertContains('s:include', $names);
        $this->assertContains('s:with', $names);
    }

    public function testNamesReturnsAllValues(): void
    {
        $names = InheritanceAttribute::names();
        $cases = InheritanceAttribute::cases();

        $this->assertCount(count($cases), $names);

        foreach ($cases as $case) {
            $this->assertContains($case->value, $names);
        }
    }
}
