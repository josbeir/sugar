<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\RawPhpNode;

/**
 * @covers \Sugar\Core\Ast\RawPhpNode
 */
#[CoversClass(RawPhpNode::class)]
final class RawPhpNodeTest extends TestCase
{
    public function testStoresPhpCode(): void
    {
        $node = new RawPhpNode(' $x = 42; ', 1, 5);

        $this->assertSame(' $x = 42; ', $node->code);
        $this->assertSame(1, $node->line);
        $this->assertSame(5, $node->column);
    }

    public function testPreservesWhitespace(): void
    {
        $code = "\n    if (\$condition) {\n        echo 'test';\n    }\n";
        $node = new RawPhpNode($code, 10, 1);

        $this->assertSame($code, $node->code);
    }

    public function testEmptyCode(): void
    {
        $node = new RawPhpNode('', 1, 1);

        $this->assertSame('', $node->code);
    }
}
