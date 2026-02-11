<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use Sugar\Exception\CompilationException;
use Sugar\Exception\SugarException;

/**
 * Renders compilation exceptions with full template context as HTML.
 */
final class HtmlTemplateExceptionRenderer implements TemplateExceptionRendererInterface
{
    /**
     * @param \Sugar\Exception\Renderer\SourceProviderInterface $sourceProvider Template source provider
     * @param \Sugar\Exception\Renderer\TemplateHighlightFormatter $formatter Highlight formatter
     */
    public function __construct(
        private readonly SourceProviderInterface $sourceProvider,
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

        $source = $this->sourceProvider->getSource($exception);
        if ($source === null || $source === '') {
            return htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $line = $exception->templateLine ?? 1;
        $column = $exception->templateColumn ?? 1;
        $highlight = $this->formatter->format($source, $line, $column);

        if ($highlight->lines === []) {
            return htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $message = $this->stripSnippetFromMessage($exception);
        $messageHtml = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
     * Remove the snippet text from formatted exception messages.
     */
    private function stripSnippetFromMessage(SugarException $exception): string
    {
        $message = $exception->getMessage();
        if ($exception->snippet === null) {
            return $message;
        }

        $needle = "\n\n" . $exception->snippet;
        if (str_contains($message, $needle)) {
            return trim(str_replace($needle, '', $message));
        }

        return $message;
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
}
