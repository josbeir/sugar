<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception\Renderer;

use PHPUnit\Framework\TestCase;
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
        $this->assertStringContainsString('2 | line two', $html);
        $this->assertStringContainsString('0 |', $html);
        $this->assertStringContainsString('^', $html);
        $this->assertStringContainsString('template: Pages/home.sugar.php line:2 column:2', $html);
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
}
