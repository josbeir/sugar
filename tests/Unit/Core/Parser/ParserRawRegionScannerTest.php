<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sugar\Core\Parser\Token;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

final class ParserRawRegionScannerTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->parser = $this->createParser();
    }

    public function testHasRawRegionsReturnsFalseWhenDirectiveMarkerMissing(): void
    {
        $this->assertFalse($this->invokeHasRawRegions('<div><?= $name ?></div>'));
    }

    public function testHasRawRegionsReturnsTrueWhenDirectiveMarkerExists(): void
    {
        $this->assertTrue($this->invokeHasRawRegions('<div s:raw><?= $name ?></div>'));
    }

    public function testTokenizeSourceReturnsRegularTokensWhenRawMarkerMissing(): void
    {
        $source = '<div><?= $name ?></div>';

        $tokens = $this->invokeTokenizeSource($source);
        $this->assertNotEmpty($tokens);
        $this->assertSame([], array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));
    }

    public function testTokenizeSourceEmitsRawBodyTokenForRawRegion(): void
    {
        $source = '<div s:raw><?= $name ?></div>';

        $tokens = $this->invokeTokenizeSource($source);
        $rawTokens = array_values(array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));

        $this->assertCount(1, $rawTokens);
        $this->assertSame('<?= $name ?>', $rawTokens[0]->content());
    }

    public function testTokenizeSourcePreservesNestedSameTagDepthForRawContent(): void
    {
        $source = '<div s:raw><div>inner</div><?= $name ?></div><div>after</div>';

        $tokens = $this->invokeTokenizeSource($source);
        $rawTokens = array_values(array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));

        $this->assertCount(1, $rawTokens);
        $this->assertSame('<div>inner</div><?= $name ?>', $rawTokens[0]->content());
    }

    public function testTokenizeSourceIgnoresSelfClosingRawTag(): void
    {
        $source = '<div s:raw /><span><?= $name ?></span>';

        $tokens = $this->invokeTokenizeSource($source);

        $this->assertSame([], array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));
    }

    public function testTokenizeSourceSkipsWhenMatchingCloseTagIsMissing(): void
    {
        $source = '<div s:raw><?= $name ?>';

        $tokens = $this->invokeTokenizeSource($source);

        $this->assertSame([], array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));
    }

    public function testTokenizeSourcePreservesNewLineCountWithinRawRegionToken(): void
    {
        $source = "<div s:raw><?= \$name ?>\n<?php echo \$other; ?>\n</div>\n<p>after</p>";

        $tokens = $this->invokeTokenizeSource($source);
        $rawTokens = array_values(array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));

        $this->assertCount(1, $rawTokens);
        $this->assertSame(2, substr_count($rawTokens[0]->content(), "\n"));
    }

    public function testTokenizeSourceHandlesGreaterThanInsideRawTagAttributes(): void
    {
        $source = '<div s:raw data-note="a > b"><?= $name ?></div>';

        $tokens = $this->invokeTokenizeSource($source);
        $rawTokens = array_values(array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));

        $this->assertCount(1, $rawTokens);
        $this->assertSame('<?= $name ?>', $rawTokens[0]->content());
    }

    public function testTokenizeSourceMatchesClosingTagCaseInsensitively(): void
    {
        $source = '<DIV s:raw><?= $name ?></Div>';

        $tokens = $this->invokeTokenizeSource($source);
        $rawTokens = array_values(array_filter($tokens, static fn(Token $token): bool => $token->isRawBody()));

        $this->assertCount(1, $rawTokens);
    }

    /**
     * @return array<\Sugar\Core\Parser\Token>
     */
    private function invokeTokenizeSource(string $source): array
    {
        $method = new ReflectionMethod($this->parser, 'tokenizeSource');
        /** @var array<\Sugar\Core\Parser\Token> $result */
        $result = $method->invoke($this->parser, $source);

        return $result;
    }

    private function invokeHasRawRegions(string $source): bool
    {
        $method = new ReflectionMethod($this->parser, 'hasRawRegions');

        return (bool)$method->invoke($this->parser, $source);
    }
}
