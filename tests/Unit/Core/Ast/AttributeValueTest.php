<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;

/**
 * Tests attribute value normalization helpers.
 */
final class AttributeValueTest extends TestCase
{
    public function testFromArrayCreatesPartsValue(): void
    {
        $value = AttributeValue::from(['prefix', 'suffix']);

        $this->assertTrue($value->isParts());
        $this->assertSame(['prefix', 'suffix'], $value->toParts());
    }
}
