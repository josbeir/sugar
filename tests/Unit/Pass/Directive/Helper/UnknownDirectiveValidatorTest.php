<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Directive\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Context\CompilationContext;
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
}
