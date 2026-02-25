<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Exception;

use ParseError;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\CompilationException;

/**
 * Tests compilation exception factory helpers.
 */
final class CompilationExceptionTest extends TestCase
{
    public function testFromCompiledTemplateParseErrorIncludesMessageAndLocation(): void
    {
        $parseError = new ParseError('syntax error, unexpected token "}"');
        $exception = CompilationException::fromCompiledTemplateParseError('/tmp/template.php', $parseError);

        $this->assertStringContainsString('Compiled template contains invalid PHP', $exception->getMessage());
        $this->assertSame('/tmp/template.php', $exception->templatePath);
        $this->assertInstanceOf(ParseError::class, $exception->getPrevious());
    }

    public function testFromCompiledComponentParseErrorIncludesMessageAndLocation(): void
    {
        $parseError = new ParseError('syntax error, unexpected token "{"');
        $exception = CompilationException::fromCompiledComponentParseError('/tmp/component.php', $parseError);

        $this->assertStringContainsString('Compiled component contains invalid PHP', $exception->getMessage());
        $this->assertSame('/tmp/component.php', $exception->templatePath);
        $this->assertInstanceOf(ParseError::class, $exception->getPrevious());
    }
}
