<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\Helper\DirectivePrefixHelper;

final class DirectivePrefixHelperTest extends TestCase
{
    private DirectivePrefixHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new DirectivePrefixHelper('s');
    }

    public function testBuildNameCreatesDirective(): void
    {
        $this->assertSame('s:if', $this->helper->buildName('if'));
        $this->assertSame('s:foreach', $this->helper->buildName('foreach'));
    }

    public function testGetPrefix(): void
    {
        $this->assertSame('s', $this->helper->getPrefix());

        $customHelper = new DirectivePrefixHelper('x');
        $this->assertSame('x', $customHelper->getPrefix());
    }

    public function testGetDirectiveSeparator(): void
    {
        $this->assertSame('s:', $this->helper->getDirectiveSeparator());

        $customHelper = new DirectivePrefixHelper('v');
        $this->assertSame('v:', $customHelper->getDirectiveSeparator());
    }

    public function testCustomPrefix(): void
    {
        $helper = new DirectivePrefixHelper('v');

        $this->assertTrue($helper->isDirective('v:if'));
        $this->assertFalse($helper->isDirective('s:if'));
        $this->assertSame('foreach', $helper->stripPrefix('v:foreach'));
        $this->assertSame('v:while', $helper->buildName('while'));
    }
}
