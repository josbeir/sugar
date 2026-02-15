<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Core\Runtime\LoopMetadata;
use Traversable;

final class LoopMetadataTest extends TestCase
{
    public function testIndexStartsAtZero(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        $this->assertSame(0, $loop->index);
    }

    public function testIterationStartsAtOne(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        $this->assertSame(1, $loop->iteration);
    }

    public function testFirstIsTrue(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        $this->assertTrue($loop->first);
    }

    public function testLastIsFalseOnFirst(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        $this->assertFalse($loop->last);
    }

    public function testLastIsTrueOnLastItem(): void
    {
        $items = ['a', 'b'];
        $loop = new LoopMetadata($items);

        $loop->next();
        $this->assertTrue($loop->last);
    }

    public function testCount(): void
    {
        $items = ['a', 'b', 'c', 'd'];
        $loop = new LoopMetadata($items);

        $this->assertSame(4, $loop->count);
    }

    public function testOddAndEven(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        // Iteration 1 (index 0)
        $this->assertTrue($loop->odd);
        $this->assertFalse($loop->even);

        // Iteration 2 (index 1)
        $loop->next();
        $this->assertFalse($loop->odd);
        $this->assertTrue($loop->even);

        // Iteration 3 (index 2)
        $loop->next();
        $this->assertTrue($loop->odd);
        $this->assertFalse($loop->even);
    }

    public function testRemaining(): void
    {
        $items = ['a', 'b', 'c', 'd'];
        $loop = new LoopMetadata($items);

        $this->assertSame(3, $loop->remaining);

        $loop->next();
        $this->assertSame(2, $loop->remaining);

        $loop->next();
        $this->assertSame(1, $loop->remaining);

        $loop->next();
        $this->assertSame(0, $loop->remaining);
    }

    public function testDepthWithoutParent(): void
    {
        $items = ['a', 'b'];
        $loop = new LoopMetadata($items);

        $this->assertSame(1, $loop->depth);
    }

    public function testDepthWithParent(): void
    {
        $outerItems = ['x', 'y'];
        $innerItems = ['a', 'b'];

        $outerLoop = new LoopMetadata($outerItems);
        $innerLoop = new LoopMetadata($innerItems, $outerLoop);

        $this->assertSame(1, $outerLoop->depth);
        $this->assertSame(2, $innerLoop->depth);
    }

    public function testParentProperty(): void
    {
        $outerItems = ['x', 'y'];
        $innerItems = ['a', 'b'];

        $outerLoop = new LoopMetadata($outerItems);
        $innerLoop = new LoopMetadata($innerItems, $outerLoop);

        $this->assertNotInstanceOf(LoopMetadata::class, $outerLoop->parent);
        $this->assertSame($outerLoop, $innerLoop->parent);
    }

    public function testNextIncrementsIndex(): void
    {
        $items = ['a', 'b', 'c'];
        $loop = new LoopMetadata($items);

        $this->assertSame(0, $loop->index);
        $this->assertSame(1, $loop->iteration);

        $loop->next();
        $this->assertSame(1, $loop->index);
        $this->assertSame(2, $loop->iteration);

        $loop->next();
        $this->assertSame(2, $loop->index);
        $this->assertSame(3, $loop->iteration);
    }

    public function testWorksWithCountable(): void
    {
        // Countable + Traversable (like many collection classes)
        $countable = new class (['a', 'b', 'c', 'd', 'e']) extends ArrayIterator implements Countable {
            public function count(): int
            {
                return 5; // Override to test countable path
            }
        };

        $loop = new LoopMetadata($countable);

        $this->assertSame(5, $loop->count);
    }

    public function testUnknownPropertyThrowsException(): void
    {
        $items = ['a', 'b'];
        $loop = new LoopMetadata($items);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown loop property: invalid');

        $loop->invalid; // @phpstan-ignore property.notFound, expr.resultUnused
    }

    public function testNestedLoopsPreserveIndependentState(): void
    {
        $outer = ['x', 'y'];
        $inner = ['a', 'b', 'c'];

        $outerLoop = new LoopMetadata($outer);
        $this->assertSame(0, $outerLoop->index);

        // Simulate inner loop iteration
        $innerLoop = new LoopMetadata($inner, $outerLoop);
        $innerLoop->next();
        $innerLoop->next();
        $this->assertSame(2, $innerLoop->index);

        // Outer loop should remain unchanged
        $this->assertSame(0, $outerLoop->index);
    }

    public function testWorksWithGenerator(): void
    {
        $generator = function () {
            yield 'a';
            yield 'b';
            yield 'c';
        };

        $loop = new LoopMetadata($generator());

        // Properties that work without knowing total count
        $this->assertSame(0, $loop->index);
        $this->assertSame(1, $loop->iteration);
        $this->assertTrue($loop->first);
        $this->assertTrue($loop->odd);
        $this->assertFalse($loop->even);

        // Count-dependent properties return null for generators
        $this->assertNull($loop->count);
        $this->assertNull($loop->last);
        $this->assertNull($loop->remaining);

        $loop->next();
        $this->assertSame(1, $loop->index);
        $this->assertFalse($loop->odd);
        $this->assertTrue($loop->even);
    }

    public function testWorksWithIterator(): void
    {
        $iterator = new ArrayIterator(['x', 'y', 'z', 'w']);
        $loop = new LoopMetadata($iterator);

        $this->assertSame(4, $loop->count);
        $this->assertSame(3, $loop->remaining);
    }

    public function testWorksWithIteratorAggregate(): void
    {
        $iteratorAggregate = new class implements IteratorAggregate, Countable {
            /**
             * @var list<string>
             */
            private array $data = ['one', 'two', 'three'];

            public function getIterator(): Traversable
            {
                return new ArrayIterator($this->data);
            }

            public function count(): int
            {
                return count($this->data);
            }
        };

        $loop = new LoopMetadata($iteratorAggregate);

        $this->assertSame(3, $loop->count);
        $this->assertTrue($loop->first);
    }

    public function testWorksWithGeneratorKeys(): void
    {
        $generator = function () {
            yield 'key1' => 'value1';
            yield 'key2' => 'value2';
        };

        $loop = new LoopMetadata($generator());

        // Generators don't provide count
        $this->assertNull($loop->count);
        $this->assertNull($loop->remaining);
        $this->assertSame(0, $loop->index);
        $this->assertTrue($loop->first);
    }
}
