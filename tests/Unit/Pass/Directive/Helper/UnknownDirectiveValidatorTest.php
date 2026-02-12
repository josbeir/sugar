<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Directive\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Compiler\CompilationContext;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Directive\IfDirective;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\Helper\UnknownDirectiveValidator;

final class UnknownDirectiveValidatorTest extends TestCase
{
    private UnknownDirectiveValidator $validator;

    private CompilationContext $context;

    protected function setUp(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');

        $this->validator = new UnknownDirectiveValidator($registry, $prefixHelper);
        $this->context = new CompilationContext(
            templatePath: 'test.sugar.php',
            source: '<div s:bloc="content"></div>',
            debug: false,
        );
    }

    public function testValidateDirectiveAttributeSuggestsInheritanceDirective(): void
    {
        $attr = new AttributeNode('s:bloc', AttributeValue::static('content'), 1, 6);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Unknown directive "bloc"');
        $this->expectExceptionMessage('Did you mean "block"');

        $this->validator->validateDirectiveAttribute($attr, $this->context, false);
    }

    public function testValidateDirectiveAttributeSkipsInheritanceWhenDisallowed(): void
    {
        $attr = new AttributeNode('s:block', AttributeValue::static('content'), 1, 6);

        $this->validator->validateDirectiveAttribute($attr, $this->context, false);
        $this->addToAssertionCount(1);
    }

    public function testValidateDirectiveAttributePrefersInheritanceSuggestion(): void
    {
        $registry = new DirectiveRegistry();
        $registry->register('bloke', IfDirective::class);

        $validator = new UnknownDirectiveValidator($registry, new DirectivePrefixHelper('s'));

        $attr = new AttributeNode('s:blok', AttributeValue::static('content'), 1, 6);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Did you mean "block"');

        $validator->validateDirectiveAttribute($attr, $this->context, true);
    }

    public function testValidateDirectiveAttributeOffsetsColumnForDirectiveName(): void
    {
        $attr = new AttributeNode('s:unknown', AttributeValue::static('value'), 1, 3);

        try {
            $this->validator->validateDirectiveAttribute($attr, $this->context, true);
            $this->fail('Expected SyntaxException for unknown directive.');
        } catch (SyntaxException $syntaxException) {
            $this->assertSame(5, $syntaxException->templateColumn);
        }
    }
}
