<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Parser\Helper\TokenStream;
use Sugar\Core\Parser\Token;

final class TokenStreamTest extends TestCase
{
    public function testPeekDoesNotAdvance(): void
    {
        $stream = $this->createStream('<?= $first ?><?= $second ?>');

        $firstPeek = $stream->peek();
        $secondPeek = $stream->peek();

        $this->assertSame($firstPeek, $secondPeek);
        $this->assertSame(0, $stream->index());
    }

    public function testNextAdvances(): void
    {
        $stream = $this->createStream('<?= $first ?><?= $second ?>');

        $first = $stream->next();
        $second = $stream->next();

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $stream->index());
    }

    public function testPeekWithOffset(): void
    {
        $stream = $this->createStream('<?= $first ?><?= $second ?>');

        $first = $stream->peek();
        $second = $stream->peek(1);

        $this->assertNotSame($first, $second);
    }

    public function testIsEndAfterConsumingAllTokens(): void
    {
        $stream = $this->createStream('<?= $first ?>');

        while (!$stream->isEnd()) {
            $stream->next();
        }

        $this->assertTrue($stream->isEnd());
        $this->assertNotInstanceOf(Token::class, $stream->next());
    }

    public function testConsumeUntilCloseTagReadsContent(): void
    {
        $stream = $this->createStream('<?= $first ?>after');

        $stream->next();

        $content = $stream->consumeUntilCloseTag();

        $this->assertSame('$first', trim($content));
        $this->assertTrue($stream->peek()?->isHtml() ?? false);
    }

    public function testConsumeUntilUsesCustomPredicate(): void
    {
        $stream = $this->createStream('<?= $first ?>after');

        $stream->next();

        $content = $stream->consumeUntil(static fn(Token $token): bool => $token->isCloseTag());

        $this->assertSame('$first', trim($content));
        $this->assertTrue($stream->peek()?->isHtml() ?? false);
    }

    private function createStream(string $source): TokenStream
    {
        return new TokenStream(Token::tokenize($source));
    }
}
