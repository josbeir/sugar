<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ElementNode;
use Sugar\Ast\TextNode;
use Sugar\Parser\Helper\ParserState;
use Sugar\Parser\Helper\TokenStream;
use Sugar\Parser\Token;

final class ParserStateTest extends TestCase
{
    public function testInitialStateIsEmpty(): void
    {
        $state = $this->createState('plain');

        $this->assertSame([], $state->nodes());
        $this->assertFalse($state->hasPendingAttribute());
        $this->assertSame(0, $state->streamIndex());
    }

    public function testAddNodeAppendsToNodes(): void
    {
        $state = $this->createState('plain');
        $node = new TextNode('Hello', 1, 1);

        $state->addNode($node);

        $this->assertCount(1, $state->nodes());
        $this->assertSame($node, $state->nodes()[0]);
    }

    public function testAddNodesMergesLists(): void
    {
        $state = $this->createState('plain');
        $nodeA = new TextNode('A', 1, 1);
        $nodeB = new TextNode('B', 1, 2);

        $state->addNodes([$nodeA, $nodeB]);

        $this->assertSame([$nodeA, $nodeB], $state->nodes());
    }

    public function testPendingAttributeTracksValue(): void
    {
        $state = $this->createState('plain');
        $pending = [
            'element' => new ElementNode('div', [], [], false, 1, 1),
            'attrIndex' => 0,
            'quote' => null,
        ];

        $state->setPendingAttribute($pending);

        $this->assertTrue($state->hasPendingAttribute());
        $this->assertSame($pending, $state->pendingAttribute());
    }

    public function testCurrentTokenPeeksStream(): void
    {
        $state = $this->createState('<?= $title ?>');

        $this->assertInstanceOf(Token::class, $state->currentToken());
    }

    public function testNormalizeOutputExpressionStripsSemicolon(): void
    {
        $state = $this->createState('plain');

        $normalized = $state->normalizeOutputExpression(' time(); ');

        $this->assertSame('time()', $normalized);
    }

    public function testColumnFromOffsetUsesSource(): void
    {
        $state = $this->createState("line one\nline two");

        $column = $state->columnFromOffset(9);

        $this->assertSame(1, $column);
    }

    public function testConsumeExpressionReadsUntilCloseTag(): void
    {
        $state = $this->createState('<?= $title ?>after');

        $state->stream->next();

        $expression = $state->consumeExpression();

        $this->assertSame('$title', $expression);
        $this->assertTrue($state->currentToken()?->isHtml() ?? false);
    }

    public function testConsumePhpBlockReadsUntilCloseTag(): void
    {
        $state = $this->createState('<?php $value = 1; ?>after');

        $state->stream->next();

        $code = $state->consumePhpBlock();

        $this->assertSame('$value = 1;', $code);
        $this->assertTrue($state->currentToken()?->isHtml() ?? false);
    }

    private function createState(string $source): ParserState
    {
        return new ParserState(new TokenStream(Token::tokenize($source)), $source);
    }
}
