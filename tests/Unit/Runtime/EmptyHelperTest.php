<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use ArrayIterator;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Sugar\Runtime\EmptyHelper;

final class EmptyHelperTest extends TestCase
{
    // Scalar Tests

    public function testIsEmptyWithNull(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty(null));
    }

    public function testIsEmptyWithFalse(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty(false));
    }

    public function testIsEmptyWithTrue(): void
    {
        $this->assertFalse(EmptyHelper::isEmpty(true));
    }

    public function testIsEmptyWithEmptyString(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty(''));
    }

    public function testIsEmptyWithNonEmptyString(): void
    {
        $this->assertFalse(EmptyHelper::isEmpty('hello'));
    }

    public function testIsEmptyWithZero(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty(0));
    }

    public function testIsEmptyWithZeroFloat(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty(0.0));
    }

    public function testIsEmptyWithZeroString(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty('0'));
    }

    public function testIsEmptyWithNonZeroNumber(): void
    {
        $this->assertFalse(EmptyHelper::isEmpty(1));
        $this->assertFalse(EmptyHelper::isEmpty(42));
        $this->assertFalse(EmptyHelper::isEmpty(3.14));
    }

    // Array Tests

    public function testIsEmptyWithEmptyArray(): void
    {
        $this->assertTrue(EmptyHelper::isEmpty([]));
    }

    public function testIsEmptyWithNonEmptyArray(): void
    {
        $this->assertFalse(EmptyHelper::isEmpty([1, 2, 3]));
        $this->assertFalse(EmptyHelper::isEmpty(['key' => 'value']));
    }

    // Countable Tests

    public function testIsEmptyWithEmptyArrayObject(): void
    {
        $arrayObject = new ArrayObject([]);
        $this->assertTrue(EmptyHelper::isEmpty($arrayObject));
    }

    public function testIsEmptyWithNonEmptyArrayObject(): void
    {
        $arrayObject = new ArrayObject([1, 2, 3]);
        $this->assertFalse(EmptyHelper::isEmpty($arrayObject));
    }

    public function testIsEmptyWithEmptyArrayIterator(): void
    {
        $iterator = new ArrayIterator([]);
        $this->assertTrue(EmptyHelper::isEmpty($iterator));
    }

    public function testIsEmptyWithNonEmptyArrayIterator(): void
    {
        $iterator = new ArrayIterator([1, 2, 3]);
        $this->assertFalse(EmptyHelper::isEmpty($iterator));
    }

    // Generator Tests

    public function testIsEmptyWithEmptyGenerator(): void
    {
        $generator = (function () {
            if (false) { // @phpstan-ignore if.alwaysFalse (intentional - makes function a generator)
                yield;
            }
        })();

        $this->assertTrue(EmptyHelper::isEmpty($generator));
    }

    public function testIsEmptyWithNonEmptyGenerator(): void
    {
        $generator = (function () {
            yield 1;
            yield 2;
        })();

        $this->assertFalse(EmptyHelper::isEmpty($generator));
    }

    // Object Tests

    public function testIsEmptyWithStdClass(): void
    {
        $object = new stdClass();
        $this->assertFalse(EmptyHelper::isEmpty($object));
    }

    public function testIsEmptyWithCustomObject(): void
    {
        $object = new class {
            public string $property = 'value';
        };

        $this->assertFalse(EmptyHelper::isEmpty($object));
    }

    // EnsureIterable Tests

    public function testEnsureIterableWithArray(): void
    {
        $array = [1, 2, 3];
        $result = EmptyHelper::ensureIterable($array);

        $this->assertSame($array, $result);
    }

    public function testEnsureIterableWithTraversable(): void
    {
        $iterator = new ArrayIterator([1, 2, 3]);
        $result = EmptyHelper::ensureIterable($iterator);

        $this->assertSame($iterator, $result);
    }

    public function testEnsureIterableWithString(): void
    {
        $result = EmptyHelper::ensureIterable('hello');

        $this->assertSame([], $result);
    }

    public function testEnsureIterableWithNull(): void
    {
        $result = EmptyHelper::ensureIterable(null);

        $this->assertSame([], $result);
    }

    public function testEnsureIterableWithObject(): void
    {
        $object = new stdClass();
        $result = EmptyHelper::ensureIterable($object);

        $this->assertSame([], $result);
    }

    public function testEnsureIterableWithNumber(): void
    {
        $result = EmptyHelper::ensureIterable(42);

        $this->assertSame([], $result);
    }
}
