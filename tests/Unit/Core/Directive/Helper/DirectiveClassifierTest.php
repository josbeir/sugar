<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistry;

final class DirectiveClassifierTest extends TestCase
{
    private DirectiveClassifier $classifier;

    private CompilationContext $context;

    protected function setUp(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');

        $this->classifier = new DirectiveClassifier($registry, $prefixHelper);
        $this->context = new CompilationContext(
            templatePath: 'test.sugar.php',
            source: '<div s:bloc="content"></div>',
            debug: false,
        );
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

    public function testValidateDirectiveAttributeSuggestsInheritanceDirective(): void
    {
        $attr = new AttributeNode('s:bloc', AttributeValue::static('content'), 1, 6);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Unknown directive "bloc"');
        $this->expectExceptionMessage('Did you mean "block"');

        $this->classifier->validateDirectiveAttribute($attr, $this->context, false);
    }

    public function testValidateDirectiveAttributeSkipsInheritanceWhenDisallowed(): void
    {
        $attr = new AttributeNode('s:block', AttributeValue::static('content'), 1, 6);

        $this->classifier->validateDirectiveAttribute($attr, $this->context, false);
        $this->addToAssertionCount(1);
    }

    public function testValidateDirectiveAttributePrefersInheritanceSuggestion(): void
    {
        $registry = new DirectiveRegistry();
        $registry->register('bloke', IfDirective::class);

        $classifier = new DirectiveClassifier($registry, new DirectivePrefixHelper('s'));

        $attr = new AttributeNode('s:blok', AttributeValue::static('content'), 1, 6);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Did you mean "block"');

        $classifier->validateDirectiveAttribute($attr, $this->context, true);
    }

    public function testValidateDirectiveAttributeOffsetsColumnForDirectiveName(): void
    {
        $attr = new AttributeNode('s:unknown', AttributeValue::static('value'), 1, 3);

        try {
            $this->classifier->validateDirectiveAttribute($attr, $this->context, true);
            $this->fail('Expected SyntaxException for unknown directive.');
        } catch (SyntaxException $syntaxException) {
            $this->assertSame(5, $syntaxException->templateColumn);
        }
    }
}
