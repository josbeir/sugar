<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Directive\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\Helper\DirectiveClassifier;

final class DirectiveClassifierTest extends TestCase
{
    private DirectiveClassifier $classifier;

    protected function setUp(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');

        $this->classifier = new DirectiveClassifier($registry, $prefixHelper);
    }

    public function testIsNonPassThroughDirectiveAttributeTreatsUnknownDirectiveAsDirective(): void
    {
        $this->assertTrue($this->classifier->isNonPassThroughDirectiveAttribute('s:unknown'));
    }

    public function testIsNonPassThroughDirectiveAttributeSkipsPassThroughDirectives(): void
    {
        $this->assertFalse($this->classifier->isNonPassThroughDirectiveAttribute('s:slot'));
    }

    public function testIsNonPassThroughDirectiveAttributeSkipsInheritanceWhenDisallowed(): void
    {
        $this->assertFalse($this->classifier->isNonPassThroughDirectiveAttribute('s:block', false));
    }

    public function testIsControlFlowDirectiveAttributeDetectsControlFlow(): void
    {
        $this->assertTrue($this->classifier->isControlFlowDirectiveAttribute('s:if'));
        $this->assertFalse($this->classifier->isControlFlowDirectiveAttribute('s:class'));
        $this->assertFalse($this->classifier->isControlFlowDirectiveAttribute('s:unknown'));
    }
}
