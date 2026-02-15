<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use ArrayIterator;
use ArrayObject;
use Iterator;
use PHPUnit\Framework\TestCase;
use stdClass;
use Sugar\Core\Exception\GeneratorNotSupportedException;
use Sugar\Core\Runtime\EmptyHelper;

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

    public function testIsEmptyWithEmptyTraversableIterator(): void
    {
        $iterator = new class implements Iterator {
            private int $position = 0;

            public function current(): mixed
            {
                return null;
            }

            public function key(): mixed
            {
                return null;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return false;
            }
        };

        $this->assertTrue(EmptyHelper::isEmpty($iterator));
    }

    public function testIsEmptyWithNonEmptyTraversableIterator(): void
    {
        $iterator = new class implements Iterator {
            private int $position = 0;

            public function current(): mixed
            {
                return 'value';
            }

            public function key(): mixed
            {
                return $this->position;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return $this->position === 0;
            }
        };

        $this->assertFalse(EmptyHelper::isEmpty($iterator));
    }

    // Generator Tests

    public function testIsEmptyWithEmptyGeneratorThrowsException(): void
    {
        // phpcs:disable Generic.CodeAnalysis.UnconditionalIfStatement.Found
        $generator = (function () {
            if (false) { // @phpstan-ignore if.alwaysFalse (intentional - makes function a generator)
                yield;
            }
        })();
        // phpcs:enable Generic.CodeAnalysis.UnconditionalIfStatement.Found

        $this->expectException(GeneratorNotSupportedException::class);
        $this->expectExceptionMessage('Generators cannot be used with s:empty or s:forelse directives');
        EmptyHelper::isEmpty($generator);
    }

    public function testIsEmptyWithNonEmptyGeneratorThrowsException(): void
    {
        $generator = (function () {
            yield 1;
            yield 2;
        })();

        $this->expectException(GeneratorNotSupportedException::class);
        $this->expectExceptionMessage('Generators cannot be used with s:empty or s:forelse directives');
        EmptyHelper::isEmpty($generator);
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
}
