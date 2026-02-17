<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Enum;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Directive\Enum\DirectiveType;

final class DirectiveTypeTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = DirectiveType::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(DirectiveType::CONTROL_FLOW, $cases);
        $this->assertContains(DirectiveType::ATTRIBUTE, $cases);
        $this->assertContains(DirectiveType::CONTENT, $cases);
        $this->assertContains(DirectiveType::PASS_THROUGH, $cases);
    }

    public function testEnumComparison(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, DirectiveType::CONTROL_FLOW);
        $this->assertNotSame(DirectiveType::CONTROL_FLOW, DirectiveType::ATTRIBUTE);
        $this->assertNotSame(DirectiveType::ATTRIBUTE, DirectiveType::CONTENT);
    }
}
