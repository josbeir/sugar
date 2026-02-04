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

    public function testIsBinding(): void
    {
        $this->assertTrue($this->helper->isBinding('s-bind:title'));
        $this->assertTrue($this->helper->isBinding('s-bind:class'));
        $this->assertFalse($this->helper->isBinding('s:if'));
        $this->assertFalse($this->helper->isBinding('class'));
    }

    public function testStripBindingPrefix(): void
    {
        $this->assertSame('title', $this->helper->stripBindingPrefix('s-bind:title'));
        $this->assertSame('class', $this->helper->stripBindingPrefix('s-bind:class'));
        $this->assertSame('prop', $this->helper->stripBindingPrefix('s-bind:prop'));
    }

    public function testStripBindingPrefixWithNonBinding(): void
    {
        $this->assertSame('s:if', $this->helper->stripBindingPrefix('s:if'));
        $this->assertSame('class', $this->helper->stripBindingPrefix('class'));
    }

    public function testBuildNameCreatesDirective(): void
    {
        $this->assertSame('s:if', $this->helper->buildName('if'));
        $this->assertSame('s:foreach', $this->helper->buildName('foreach'));
    }

    public function testBuildBindingName(): void
    {
        $this->assertSame('s-bind:title', $this->helper->buildBindingName('title'));
        $this->assertSame('s-bind:class', $this->helper->buildBindingName('class'));
        $this->assertSame('s-bind:disabled', $this->helper->buildBindingName('disabled'));
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

    public function testGetBindingPrefix(): void
    {
        $this->assertSame('s-bind:', $this->helper->getBindingPrefix());

        $customHelper = new DirectivePrefixHelper('x');
        $this->assertSame('x-bind:', $customHelper->getBindingPrefix());
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
