<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception\Renderer;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\CompilationException;
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Exception\Renderer\SourceProviderInterface;
use Sugar\Exception\SugarException;
use Sugar\Exception\TemplateRuntimeException;

final class HtmlTemplateExceptionRendererTest extends TestCase
{
    public function testRendersFullTemplateHtml(): void
    {
        $provider = new class implements SourceProviderInterface {
            public function getSource(SugarException $exception): ?string
            {
                if ($exception->templatePath === null) {
                    return null;
                }

                return "line one\nline two\nline three";
            }
        };

        $renderer = new HtmlTemplateExceptionRenderer($provider);
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
        $provider = new class implements SourceProviderInterface {
            public function getSource(SugarException $exception): ?string
            {
                if ($exception->templatePath === null) {
                    return null;
                }

                return 'line one';
            }
        };

        $renderer = new HtmlTemplateExceptionRenderer($provider);
        $exception = new TemplateRuntimeException('Runtime failed');

        $html = $renderer->render($exception);

        $this->assertSame('Runtime failed', $html);
    }
}
