<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Config\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;

final class DirectivePrefixHelperTest extends TestCase
{
    private DirectivePrefixHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new DirectivePrefixHelper('s');
    }

    public function testIsDirective(): void
    {
        $this->assertTrue($this->helper->isDirective('s:if'));
        $this->assertFalse($this->helper->isDirective('class'));
    }

    public function testStripPrefix(): void
    {
        $this->assertSame('if', $this->helper->stripPrefix('s:if'));
        $this->assertSame('class', $this->helper->stripPrefix('class'));
    }

    public function testBuildName(): void
    {
        $this->assertSame('s:while', $this->helper->buildName('while'));
    }

    public function testGetDirectiveSeparator(): void
    {
        $this->assertSame('s:', $this->helper->getDirectiveSeparator());

        $customHelper = new DirectivePrefixHelper('x');
        $this->assertSame('x:', $customHelper->getDirectiveSeparator());
    }

    public function testCustomPrefix(): void
    {
        $customHelper = new DirectivePrefixHelper('x');

        $this->assertTrue($customHelper->isDirective('x:if'));
        $this->assertSame('if', $customHelper->stripPrefix('x:if'));
    }

    public function testElementPrefixHelpers(): void
    {
        $customHelper = new DirectivePrefixHelper('v');

        $this->assertTrue($customHelper->hasElementPrefix('v-card'));
        $this->assertSame('card', $customHelper->stripElementPrefix('v-card'));
    }

    public function testInheritanceAttribute(): void
    {
        $helper = new DirectivePrefixHelper('v');

        $this->assertTrue($helper->isInheritanceAttribute('v:extends'));
        $this->assertTrue($helper->isInheritanceAttribute('extends'));
        $this->assertFalse($helper->isInheritanceAttribute('v:if'));
    }
}
