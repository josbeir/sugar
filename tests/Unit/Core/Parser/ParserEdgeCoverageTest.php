<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Parser;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Parser\Token;
use Sugar\Core\Parser\TokenStream;
use Sugar\Core\Parser\TokenType;

final class ParserEdgeCoverageTest extends TestCase
{
    public function testConsumeClosingTagReturnsWhenNextTokenIsNotSlash(): void
    {
        $parser = new Parser(new SugarConfig());
        $stream = new TokenStream([
            new Token(TokenType::TagOpen, '<', 1, 1),
            new Token(TokenType::TagName, 'div', 1, 2),
            new Token(TokenType::Eof, '', 1, 5),
        ]);

        $this->invokePrivate($parser, 'consumeClosingTag', [$stream]);

        $this->assertSame(TokenType::TagOpen, $stream->current()->type);
    }

    public function testParseQuotedAttributeValueConsumesUnexpectedTokenTypes(): void
    {
        $parser = new Parser(new SugarConfig());
        $stream = new TokenStream([
            new Token(TokenType::QuoteOpen, '"', 1, 1),
            new Token(TokenType::AttributeName, 'unexpected', 1, 2),
            new Token(TokenType::QuoteClose, '"', 1, 12),
            new Token(TokenType::Eof, '', 1, 13),
        ]);

        $value = $this->invokePrivate($parser, 'parseQuotedAttributeValue', [$stream]);

        $this->assertInstanceOf(AttributeValue::class, $value);
        $this->assertSame('', $value->static);
        $this->assertSame(TokenType::Eof, $stream->current()->type);
    }

    public function testSkipTokenConsumesCurrentAndReturnsNull(): void
    {
        $parser = new Parser(new SugarConfig());
        $stream = new TokenStream([
            new Token(TokenType::Text, 'x', 1, 1),
            new Token(TokenType::Eof, '', 1, 2),
        ]);

        $result = $this->invokePrivate($parser, 'skipToken', [$stream]);

        $this->assertNull($result);
        $this->assertSame(TokenType::Eof, $stream->current()->type);
    }

    /**
     * @param array<mixed> $args
     */
    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($instance);
        $reflectionMethod = $reflection->getMethod($method);

        return $reflectionMethod->invokeArgs($instance, $args);
    }
}
