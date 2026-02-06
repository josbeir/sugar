<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\CompilationException;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\SugarException;
use Sugar\Exception\SyntaxException;
use Sugar\Exception\TemplateNotFoundException;
use Sugar\Exception\TemplateRuntimeException;
use Sugar\Exception\UnknownDirectiveException;
use Sugar\Exception\UnsupportedNodeException;

/**
 * Test exception hierarchy and basic instantiation
 *
 * Ensures all exception classes can be instantiated and have correct inheritance.
 */
final class ExceptionHierarchyTest extends TestCase
{
    public function testCompilationExceptionExtendsBaseException(): void
    {
        $exception = new CompilationException('Test message');

        $this->assertInstanceOf(SugarException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testTemplateRuntimeExceptionExtendsBaseException(): void
    {
        $exception = new TemplateRuntimeException('Runtime error');

        $this->assertInstanceOf(SugarException::class, $exception);
        $this->assertSame('Runtime error', $exception->getMessage());
    }

    public function testTemplateNotFoundExceptionExtendsCompilationException(): void
    {
        $exception = new TemplateNotFoundException('Template not found: home.sugar.php');

        $this->assertInstanceOf(CompilationException::class, $exception);
        $this->assertInstanceOf(SugarException::class, $exception);
        $this->assertStringContainsString('home.sugar.php', $exception->getMessage());
    }

    public function testComponentNotFoundExceptionExtendsRuntimeException(): void
    {
        $exception = new ComponentNotFoundException('Component not found: Button');

        $this->assertInstanceOf(TemplateRuntimeException::class, $exception);
        $this->assertInstanceOf(SugarException::class, $exception);
        $this->assertStringContainsString('Button', $exception->getMessage());
    }

    public function testSyntaxExceptionExtendsCompilationException(): void
    {
        $exception = new SyntaxException('Syntax error on line 5');

        $this->assertInstanceOf(CompilationException::class, $exception);
        $this->assertInstanceOf(SugarException::class, $exception);
        $this->assertStringContainsString('line 5', $exception->getMessage());
    }

    public function testUnknownDirectiveExceptionExtendsCompilationException(): void
    {
        $exception = new UnknownDirectiveException('unknown');

        $this->assertInstanceOf(CompilationException::class, $exception);
        $this->assertSame('Unknown directive "unknown"', $exception->getMessage());
    }

    public function testUnsupportedNodeExceptionExtendsCompilationException(): void
    {
        $exception = UnsupportedNodeException::forNodeType('CustomNode');

        $this->assertInstanceOf(CompilationException::class, $exception);
        $this->assertStringContainsString('CustomNode', $exception->getMessage());
    }

    public function testExceptionsCanBeThrown(): void
    {
        $this->expectException(CompilationException::class);
        throw new CompilationException('Test throw');
    }

    public function testExceptionsCanBeCaught(): void
    {
        try {
            throw new SyntaxException('Parse error');
        } catch (CompilationException $compilationException) {
            $this->assertInstanceOf(SyntaxException::class, $compilationException);
            $this->assertSame('Parse error', $compilationException->getMessage());
        }
    }

    public function testRuntimeExceptionsCanBeCaughtAsSugarException(): void
    {
        try {
            throw new ComponentNotFoundException('Missing component');
        } catch (SugarException $sugarException) {
            $this->assertInstanceOf(ComponentNotFoundException::class, $sugarException);
            $this->assertSame('Missing component', $sugarException->getMessage());
        }
    }
}
