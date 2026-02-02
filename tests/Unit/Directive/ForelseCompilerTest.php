<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\ForelseCompiler;

final class ForelseCompilerTest extends TestCase
{
    private ForelseCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ForelseCompiler();
    }

    public function testCompilesForelseWithNoneMarker(): void
    {
        $forelse = new DirectiveNode(
            name: 'forelse',
            expression: '$users as $user',
            children: [
                new TextNode('User: ', 1, 0),
                new DirectiveNode(
                    name: 'none',
                    expression: '',
                    children: [new TextNode('No users found', 2, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($forelse);

        // Should have: if (!empty), loop setup, foreach, content, loop end, endforeach, else, none content, endif
        $this->assertGreaterThan(5, count($result));

        // Check for empty check
        $hasEmptyCheck = false;
        foreach ($result as $node) {
            if ($node instanceof RawPhpNode && str_contains($node->code, '!empty')) {
                $hasEmptyCheck = true;
                break;
            }
        }

        $this->assertTrue($hasEmptyCheck, 'Should have !empty check');
    }

    public function testForelseWithoutNoneMarker(): void
    {
        $forelse = new DirectiveNode(
            name: 'forelse',
            expression: '$items as $item',
            children: [new TextNode('Item', 1, 0)],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        // Should compile like regular foreach (no empty fallback)
        $result = $this->compiler->compile($forelse);

        // Check it still generates loop structure
        $this->assertGreaterThan(3, count($result));
    }

    public function testMultipleNoneMarkersThrowException(): void
    {
        $forelse = new DirectiveNode(
            name: 'forelse',
            expression: '$items as $item',
            children: [
                new DirectiveNode(
                    name: 'none',
                    expression: '',
                    children: [new TextNode('First none', 1, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
                new DirectiveNode(
                    name: 'none',
                    expression: '',
                    children: [new TextNode('Second none', 1, 0)],
                    elseChildren: null,
                    line: 3,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forelse can only have one none/empty marker');

        $this->compiler->compile($forelse);
    }

    public function testForelseExtractsCollection(): void
    {
        $forelse = new DirectiveNode(
            name: 'forelse',
            expression: '$users as $user',
            children: [
                new TextNode('User', 1, 0),
                new DirectiveNode(
                    name: 'none',
                    expression: '',
                    children: [new TextNode('No users', 2, 0)],
                    elseChildren: null,
                    line: 2,
                    column: 0,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($forelse);

        // Check that collection is extracted properly for empty check
        $hasCollectionCheck = false;
        foreach ($result as $node) {
            if ($node instanceof RawPhpNode && str_contains($node->code, '$users')) {
                $hasCollectionCheck = true;
                break;
            }
        }

        $this->assertTrue($hasCollectionCheck);
    }
}
