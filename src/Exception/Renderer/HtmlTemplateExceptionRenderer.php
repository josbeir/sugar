<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use Sugar\Exception\CompilationException;
use Sugar\Exception\SugarException;
use Sugar\Loader\TemplateLoaderInterface;
use Throwable;

/**
 * Renders compilation exceptions with full template context as HTML.
 */
final class HtmlTemplateExceptionRenderer implements TemplateExceptionRendererInterface
{
    /**
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Exception\Renderer\TemplateHighlightFormatter $formatter Highlight formatter
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateHighlightFormatter $formatter = new TemplateHighlightFormatter(),
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(SugarException $exception): string
    {
        if (!$exception instanceof CompilationException) {
            return htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $source = $this->loadSource($exception);
        if ($source === null || $source === '') {
            return htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $line = $exception->templateLine ?? 1;
        $column = $exception->templateColumn ?? 1;
        $highlight = $this->formatter->format($source, $line, $column);

        if ($highlight->lines === []) {
            return htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $messageHtml = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationHtml = $this->formatLocation($exception);

        $linesHtml = [];
        foreach ($highlight->lines as $lineInfo) {
            $escaped = htmlspecialchars($lineInfo->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($lineInfo->isCaretLine) {
                $linesHtml[] = '<span class="sugar-error-caret">' . $escaped . '</span>';
                continue;
            }

            if ($lineInfo->isErrorLine) {
                $linesHtml[] = '<span class="sugar-error-line">' . $escaped . '</span>';
                continue;
            }

            $linesHtml[] = $escaped;
        }

        $templateHtml = implode("\n", $linesHtml);

        return sprintf(
            '<div class="sugar-exception">' .
            '<div class="sugar-exception-message">%s</div>' .
            '<div class="sugar-exception-location">%s</div>' .
            '<pre class="sugar-exception-template">%s</pre>' .
            '</div>',
            $messageHtml,
            $locationHtml,
            $templateHtml,
        );
    }

    /**
     * Format a template location string for display.
     */
    private function formatLocation(SugarException $exception): string
    {
        if ($exception->templatePath === null) {
            return '';
        }

        $location = 'template: ' . $exception->templatePath;
        if ($exception->templateLine !== null) {
            $location .= ' line:' . $exception->templateLine;
        }

        if ($exception->templateColumn !== null && $exception->templateColumn > 0) {
            $location .= ' column:' . $exception->templateColumn;
        }

        return htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Load template source for the provided exception.
     */
    private function loadSource(SugarException $exception): ?string
    {
        if ($exception->templatePath === null) {
            return null;
        }

        try {
            return $this->loader->load($exception->templatePath);
        } catch (Throwable) {
            return null;
        }
    }
}
