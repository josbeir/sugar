<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Util;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Util\ValueNormalizer;

final class ValueNormalizerTest extends TestCase
{
    public function testToDisplayStringNormalizesSupportedValues(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $this->assertSame('', ValueNormalizer::toDisplayString(null));
        $this->assertSame('hello', ValueNormalizer::toDisplayString('hello'));
        $this->assertSame('42', ValueNormalizer::toDisplayString(42));
        $this->assertSame('1', ValueNormalizer::toDisplayString(true));
        $this->assertSame('stringable', ValueNormalizer::toDisplayString($stringable));
    }

    public function testToDisplayStringReturnsEmptyForUnsupportedValues(): void
    {
        $this->assertSame('', ValueNormalizer::toDisplayString(['nope']));
        $this->assertSame('', ValueNormalizer::toDisplayString(new class {
        }));
    }

    public function testToIterableReturnsInputForArraysAndTraversables(): void
    {
        $this->assertSame([1, 2], iterator_to_array(ValueNormalizer::toIterable([1, 2])));
        $this->assertSame([1, 2], iterator_to_array(ValueNormalizer::toIterable(new ArrayIterator([1, 2]))));
        $this->assertSame([], iterator_to_array(ValueNormalizer::toIterable('not-iterable')));
    }

    public function testToAttributeValueNormalizesStringablesAndUnsupportedValues(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'str';
            }
        };

        $this->assertSame('ok', ValueNormalizer::toAttributeValue('ok'));
        $this->assertSame(12, ValueNormalizer::toAttributeValue(12));
        $this->assertEqualsWithDelta(3.14, ValueNormalizer::toAttributeValue(3.14), PHP_FLOAT_EPSILON);
        $this->assertFalse(ValueNormalizer::toAttributeValue(false));
        $this->assertSame('str', ValueNormalizer::toAttributeValue($stringable));
        $this->assertNull(ValueNormalizer::toAttributeValue(['bad']));
        $this->assertNull(ValueNormalizer::toAttributeValue(new class {
        }));
    }

    public function testNormalizeStringListRemovesEmptyDuplicatesAndSorts(): void
    {
        $result = ValueNormalizer::normalizeStringList([' beta ', 'alpha', '', 'alpha', 123]);

        $this->assertSame(['alpha', 'beta'], $result);
    }

    public function testNormalizeStringListReturnsNullWhenNoValidStringsRemain(): void
    {
        $result = ValueNormalizer::normalizeStringList(['   ', '', 123, null]);

        $this->assertNull($result);
    }
}
