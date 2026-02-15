<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\RuntimeCallNode;

final class RuntimeCallNodeTest extends TestCase
{
    public function testStoresCallableAndArguments(): void
    {
        $node = new RuntimeCallNode(
            callableExpression: 'Foo::bar',
            arguments: ['$value', '1'],
            line: 3,
            column: 7,
        );

        $this->assertSame('Foo::bar', $node->callableExpression);
        $this->assertSame(['$value', '1'], $node->arguments);
        $this->assertSame(3, $node->line);
        $this->assertSame(7, $node->column);
    }
}
