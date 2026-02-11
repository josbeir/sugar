<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception\Renderer;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Exception\CompilationException;
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Exception\TemplateRuntimeException;
use Sugar\Loader\TemplateLoaderInterface;

final class HtmlTemplateExceptionRendererTest extends TestCase
{
    public function testRendersFullTemplateHtml(): void
    {
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path): string
            {
                return "line one\nline two\nline three";
            }

            public function resolve(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function resolveToFilePath(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function loadComponent(string $name): string
            {
                return '';
            }

            public function getComponentPath(string $name): string
            {
                return $name;
            }

            public function getComponentFilePath(string $name): string
            {
                return $name;
            }
        };

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
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path): string
            {
                return 'line one';
            }

            public function resolve(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function resolveToFilePath(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function loadComponent(string $name): string
            {
                return '';
            }

            public function getComponentPath(string $name): string
            {
                return $name;
            }

            public function getComponentFilePath(string $name): string
            {
                return $name;
            }
        };

        $renderer = new HtmlTemplateExceptionRenderer($loader);
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertSame('Runtime failed', $html);
    }

    public function testNonCompilationExceptionWrapsDocumentWithStyles(): void
    {
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path): string
            {
                return 'line one';
            }

            public function resolve(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function resolveToFilePath(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function loadComponent(string $name): string
            {
                return '';
            }

            public function getComponentPath(string $name): string
            {
                return $name;
            }

            public function getComponentFilePath(string $name): string
            {
                return $name;
            }
        };

        $renderer = new HtmlTemplateExceptionRenderer(
            loader: $loader,
            includeStyles: true,
            wrapDocument: true,
        );
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    public function testCompilationExceptionWithoutSourceSkipsTemplateMarkup(): void
    {
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path): string
            {
                throw new RuntimeException('Missing template');
            }

            public function resolve(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function resolveToFilePath(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function loadComponent(string $name): string
            {
                return '';
            }

            public function getComponentPath(string $name): string
            {
                return $name;
            }

            public function getComponentFilePath(string $name): string
            {
                return $name;
            }
        };

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

    public function testFormatTraceReturnsHtmlForFrames(): void
    {
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path): string
            {
                return 'line one';
            }

            public function resolve(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function resolveToFilePath(string $path, string $currentTemplate = ''): string
            {
                return $path;
            }

            public function loadComponent(string $name): string
            {
                return '';
            }

            public function getComponentPath(string $name): string
            {
                return $name;
            }

            public function getComponentFilePath(string $name): string
            {
                return $name;
            }
        };

        $renderer = new HtmlTemplateExceptionRenderer($loader);

        $output = $renderer->formatTrace(new Exception('Has trace'));

        $this->assertStringContainsString('sugar-exception-trace', $output);
        $this->assertStringContainsString('Sugar stack trace', $output);
    }
}
