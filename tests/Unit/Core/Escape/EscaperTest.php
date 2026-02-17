<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Escape;

use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Escape\Escaper;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Test context-aware escaping (security-critical - 100% coverage required)
 */
final class EscaperTest extends TestCase
{
    use ExecuteTemplateTrait;

    private Escaper $escaper;

    protected function setUp(): void
    {
        $this->escaper = new Escaper();
    }

    // HTML Context Tests

    public function testEscapesHtmlEntities(): void
    {
        $result = $this->escaper->escape('<script>alert("xss")</script>', OutputContext::HTML);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testEscapesHtmlQuotes(): void
    {
        $result = $this->escaper->escape('Hello "World" & \'Test\'', OutputContext::HTML);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&apos;', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    public function testEscapesHtmlAmpersand(): void
    {
        $result = $this->escaper->escape('AT&T', OutputContext::HTML);

        $this->assertSame('AT&amp;T', $result);
    }

    #[DataProvider('xssVectorsProvider')]
    public function testEscapesXssVectors(string $vector): void
    {
        $result = $this->escaper->escape($vector, OutputContext::HTML);

        // Verify dangerous tags/attributes are escaped (not executable)
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('<svg', $result);
        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('&lt;', $result); // Contains escaped version
    }

    /**
     * @return \Iterator<string, array<string>>
     */
    public static function xssVectorsProvider(): Iterator
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'svg onload' => ['<svg onload=alert(1)>'];
        yield 'javascript protocol' => ['<a href="javascript:alert(1)">click</a>'];
        yield 'script with encoding' => ['<script>alert(String.fromCharCode(88,83,83))</script>'];
        yield 'iframe src' => ['<iframe src="javascript:alert(1)">'];
    }

    // JavaScript Context Tests

    public function testEscapesStringForJavaScript(): void
    {
        $result = $this->escaper->escape('Hello "World"', OutputContext::JAVASCRIPT);

        $this->assertStringStartsWith('"', $result);
        $this->assertStringEndsWith('"', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testEscapesJavaScriptDangerousChars(): void
    {
        $result = $this->escaper->escape('<script>alert(1)</script>', OutputContext::JAVASCRIPT);

        // Should use JSON_HEX_TAG to escape < and >
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    public function testEscapesArrayAsJsonInJavaScript(): void
    {
        $result = $this->escaper->escape(['foo' => 'bar', 'baz' => 123], OutputContext::JAVASCRIPT);

        $this->assertStringContainsString('foo', $result);
        $this->assertStringContainsString('bar', $result);
        $this->assertStringContainsString('123', $result);
    }

    // JSON Context Tests

    public function testEscapesJsonContext(): void
    {
        $result = $this->escaper->escape('<tag>', OutputContext::JSON);

        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    // URL Context Tests

    public function testEscapesUrl(): void
    {
        $result = $this->escaper->escape('hello world?foo=bar&baz=<test>', OutputContext::URL);

        $this->assertStringNotContainsString(' ', $result);
        $this->assertStringContainsString('%20', $result);
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    public function testEscapesSpecialUrlCharacters(): void
    {
        $result = $this->escaper->escape('foo/bar?baz=test&key=value', OutputContext::URL);

        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString('?', $result);
        $this->assertStringNotContainsString('&', $result);
        $this->assertStringNotContainsString('=', $result);
    }

    // HTML Attribute Context Tests

    public function testEscapesHtmlAttribute(): void
    {
        $result = $this->escaper->escape('value "with" quotes', OutputContext::HTML_ATTRIBUTE);

        $this->assertStringNotContainsString('"', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    public function testEscapesAttributeWithScriptAttempt(): void
    {
        $result = $this->escaper->escape('" onload="alert(1)', OutputContext::HTML_ATTRIBUTE);

        $this->assertStringNotContainsString('" onload="', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    public function testEscapesJsonForAttribute(): void
    {
        $result = $this->escaper->escape(['name' => 'a"b'], OutputContext::JSON_ATTRIBUTE);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringNotContainsString('"', $result);
    }

    // CSS Context Tests

    public function testEscapesCss(): void
    {
        $result = $this->escaper->escape('color: red; background: url(javascript:alert(1))', OutputContext::CSS);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testEscapesCssSpecialCharacters(): void
    {
        $result = $this->escaper->escape('<>', OutputContext::CSS);

        $this->assertStringContainsString('\\3c', $result);
        $this->assertStringContainsString('\\3e', $result);
    }

    // RAW Context Tests

    public function testRawContextDoesNotEscape(): void
    {
        $input = '<script>alert("xss")</script>';
        $result = $this->escaper->escape($input, OutputContext::RAW);

        $this->assertSame($input, $result);
    }

    public function testRawReturnsEmptyStringForArray(): void
    {
        $result = $this->escaper->escape(['value'], OutputContext::RAW);

        $this->assertSame('', $result);
    }

    // Edge Cases

    public function testEscapesNull(): void
    {
        $result = $this->escaper->escape(null, OutputContext::HTML);

        $this->assertSame('', $result);
    }

    public function testEscapesInteger(): void
    {
        $result = $this->escaper->escape(123, OutputContext::HTML);

        $this->assertSame('123', $result);
    }

    public function testEscapesFloat(): void
    {
        $result = $this->escaper->escape(123.45, OutputContext::HTML);

        $this->assertSame('123.45', $result);
    }

    public function testEscapesBoolean(): void
    {
        $result = $this->escaper->escape(true, OutputContext::HTML);
        $this->assertSame('1', $result);

        $result = $this->escaper->escape(false, OutputContext::HTML);
        $this->assertSame('', $result);
    }

    public function testHtmlThrowsForArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot auto-escape arrays/objects in HTML context');

        $this->escaper->escape(['value'], OutputContext::HTML);
    }

    public function testGenerateEscapeCodeForHtml(): void
    {
        $code = $this->escaper->generateEscapeCode('$variable', OutputContext::HTML);

        $this->assertStringContainsString('Escaper::html', $code);
        $this->assertStringContainsString('$variable', $code);
    }

    public function testGenerateEscapeCodeForJavascript(): void
    {
        $code = $this->escaper->generateEscapeCode('$data', OutputContext::JAVASCRIPT);

        $this->assertStringContainsString('Escaper::js', $code);
        $this->assertStringContainsString('$data', $code);
    }

    public function testGenerateEscapeCodeForUrl(): void
    {
        $code = $this->escaper->generateEscapeCode('$url', OutputContext::URL);

        $this->assertStringContainsString('Escaper::url', $code);
        $this->assertStringContainsString('$url', $code);
    }

    public function testGenerateEscapeCodeForJson(): void
    {
        $code = $this->escaper->generateEscapeCode('$payload', OutputContext::JSON);

        $this->assertStringContainsString('Escaper::json', $code);
        $this->assertStringContainsString('$payload', $code);
    }

    public function testGenerateEscapeCodeForJsonAttribute(): void
    {
        $code = $this->escaper->generateEscapeCode('$payload', OutputContext::JSON_ATTRIBUTE);

        $this->assertStringContainsString('Escaper::attrJson', $code);
        $this->assertStringContainsString('$payload', $code);
    }

    public function testGenerateEscapeCodeForHtmlAttribute(): void
    {
        $code = $this->escaper->generateEscapeCode('$value', OutputContext::HTML_ATTRIBUTE);

        $this->assertStringContainsString('Escaper::attr', $code);
        $this->assertStringContainsString('$value', $code);
    }

    public function testGenerateEscapeCodeForCss(): void
    {
        $code = $this->escaper->generateEscapeCode('$color', OutputContext::CSS);

        $this->assertStringContainsString('Escaper::css', $code);
        $this->assertStringContainsString('$color', $code);
    }

    public function testGenerateEscapeCodeForRaw(): void
    {
        $code = $this->escaper->generateEscapeCode('$content', OutputContext::RAW);

        // Raw context should return expression as-is
        $this->assertSame('$content', $code);
    }

    public function testGeneratedCodeIsValidPhp(): void
    {
        $code = $this->escaper->generateEscapeCode('$var', OutputContext::HTML);

        $result = $this->evaluateExpression($code, [
            'var' => '<script>alert("xss")</script>',
        ]);

        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result);
    }
}
