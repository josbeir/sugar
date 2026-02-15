<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Parser\Helper\PipeParser;

final class PipeParserTest extends TestCase
{
    public function testParseWithoutPipesReturnsNullPipes(): void
    {
        $result = PipeParser::parse('$name');

        $this->assertSame('$name', $result['expression']);
        $this->assertNull($result['pipes']);
    }

    public function testParseSplitsPipeChain(): void
    {
        $result = PipeParser::parse('$name |> strtoupper(...) |> substr(..., 0, 10)');

        $this->assertSame('$name', $result['expression']);
        $this->assertSame(['strtoupper(...)', 'substr(..., 0, 10)'], $result['pipes']);
    }

    public function testParseTrimsWhitespaceAroundPipes(): void
    {
        $result = PipeParser::parse('  $value   |>   trim(...)   |>  strtolower(...)  ');

        $this->assertSame('$value', $result['expression']);
        $this->assertSame(['trim(...)', 'strtolower(...)'], $result['pipes']);
    }

    public function testParseFiltersRawPipeAndSetsRawFlag(): void
    {
        $result = PipeParser::parse('$name |> raw() |> strtoupper(...)');

        $this->assertSame('$name', $result['expression']);
        $this->assertSame(['strtoupper(...)'], $result['pipes']);
        $this->assertTrue($result['raw']);
        $this->assertFalse($result['json']);
    }

    public function testParseRawOnlyReturnsNullPipesWithRawFlag(): void
    {
        $result = PipeParser::parse('$name |> raw()');

        $this->assertSame('$name', $result['expression']);
        $this->assertNull($result['pipes']);
        $this->assertTrue($result['raw']);
        $this->assertFalse($result['json']);
    }

    public function testParseFiltersJsonPipeAndSetsJsonFlag(): void
    {
        $result = PipeParser::parse('$data |> json() |> trim(...)');

        $this->assertSame('$data', $result['expression']);
        $this->assertSame(['trim(...)'], $result['pipes']);
        $this->assertFalse($result['raw']);
        $this->assertTrue($result['json']);
    }

    public function testParseJsonOnlyReturnsNullPipesWithJsonFlag(): void
    {
        $result = PipeParser::parse('$data |> json()');

        $this->assertSame('$data', $result['expression']);
        $this->assertNull($result['pipes']);
        $this->assertFalse($result['raw']);
        $this->assertTrue($result['json']);
    }
}
