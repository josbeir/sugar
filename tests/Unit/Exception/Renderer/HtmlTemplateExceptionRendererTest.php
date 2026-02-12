<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception\Renderer;

use Exception;
use PHPUnit\Framework\TestCase;
use Sugar\Exception\CompilationException;
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Exception\TemplateRuntimeException;
use Sugar\Loader\StringTemplateLoader;

final class HtmlTemplateExceptionRendererTest extends TestCase
{
    public function testRendersFullTemplateHtml(): void
    {
        $loader = $this->createLoader([
            'Pages/home.sugar.php' => "line one\nline two\nline three",
        ]);

        $renderer = new HtmlTemplateExceptionRenderer($loader);
        $exception = new CompilationException(
            message: 'Compile failed',
            templatePath: 'Pages/home.sugar.php',
            templateLine: 2,
            templateColumn: 2,
        );

        $html = $renderer->render($exception);

        $this->assertStringContainsString('<pre class="sugar-exception-template">', $html);
        $this->assertStringContainsString('<div class="sugar-exception-title">Sugar</div>', $html);
        $this->assertStringContainsString('CompilationException', $html);
        $this->assertStringContainsString('2 | line two', $html);
        $this->assertStringContainsString('  |', $html);
        $this->assertStringContainsString('^', $html);
        $this->assertStringContainsString('template: Pages/home.sugar.php line:2 column:2', $html);
        $this->assertStringContainsString('<details class="sugar-exception-trace">', $html);
        $this->assertStringContainsString('<summary>Sugar stack trace</summary>', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    public function testNonCompilationExceptionFallsBackToMessage(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer($loader);
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertStringContainsString('Runtime failed', $html);
        $this->assertStringContainsString('TemplateRuntimeException', $html);
        $this->assertStringContainsString('sugar-exception', $html);
    }

    public function testNonCompilationExceptionWrapsDocumentWithStyles(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            includeStyles: true,
            wrapDocument: true,
        );
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('TemplateRuntimeException', $html);
    }

    public function testNonCompilationExceptionWrapsDocumentWithoutStyles(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            includeStyles: false,
            wrapDocument: true,
        );
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringContainsString('TemplateRuntimeException', $html);
    }

    public function testNonCompilationExceptionWithoutStylesDoesNotIncludeStyleBlock(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            includeStyles: false,
            wrapDocument: false,
        );
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertStringContainsString('Runtime failed', $html);
        $this->assertStringContainsString('TemplateRuntimeException', $html);
        $this->assertStringNotContainsString('<style>', $html);
    }

    public function testCompilationExceptionWithoutSourceSkipsTemplateMarkup(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            includeStyles: false,
            wrapDocument: true,
        );
        $exception = new CompilationException(
            message: 'Compile failed',
            templatePath: 'missing.sugar.php',
            templateLine: 1,
            templateColumn: 1,
        );

        $html = $renderer->render($exception);

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('sugar-exception-template', $html);
    }

    public function testCompilationExceptionWithoutTemplatePathOmitsLocation(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer($loader);
        $exception = new CompilationException(
            message: 'Compile failed',
        );

        $html = $renderer->render($exception);

        $this->assertStringContainsString('CompilationException', $html);
        $this->assertStringNotContainsString('template:', $html);
        $this->assertStringNotContainsString('<pre class="sugar-exception-template">', $html);
    }

    public function testFormatTraceReturnsHtmlForFrames(): void
    {
        $loader = $this->createLoader();

        $renderer = new HtmlTemplateExceptionRenderer($loader);

        $output = $renderer->formatTrace(new Exception('Has trace'));

        $this->assertStringContainsString('sugar-exception-trace', $output);
        $this->assertStringContainsString('Sugar stack trace', $output);
    }

    public function testFormatTraceHonorsMaxFrames(): void
    {
        $loader = $this->createLoader();
        $exception = $this->createExceptionWithTraceFrames();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            traceMaxFrames: 1,
        );

        $output = $renderer->formatTrace($exception);

        $this->assertSame(2, substr_count($output, '<li>'));
        $this->assertStringContainsString('trace truncated', $output);
    }

    public function testFormatTraceCanIncludeArguments(): void
    {
        $loader = $this->createLoader();
        $exception = $this->createExceptionWithTraceFrames();

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            traceIncludeArguments: true,
            traceArgumentMaxLength: 5,
        );

        $output = $renderer->formatTrace($exception);

        $this->assertStringContainsString('(...)', $output);
        $this->assertStringContainsString('secondTraceFrame', $output);
    }

    /**
     * @param array<string, string> $templates
     */
    private function createLoader(array $templates = []): StringTemplateLoader
    {
        return new StringTemplateLoader(templates: $templates);
    }

    private function createExceptionWithTraceFrames(): Exception
    {
        try {
            $this->firstTraceFrame('abcdef', ['key' => 'value', 'other' => 1]);
        } catch (Exception $exception) {
            return $exception;
        }

        throw new Exception('Unable to generate trace frames');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function firstTraceFrame(string $value, array $payload): void
    {
        $this->secondTraceFrame($value, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function secondTraceFrame(string $value, array $payload): void
    {
        $key = $payload['key'] ?? null;
        $suffix = is_string($key) ? $key : '';

        throw new Exception($value . ':' . $suffix, previous: new Exception('previous'));
    }
}
