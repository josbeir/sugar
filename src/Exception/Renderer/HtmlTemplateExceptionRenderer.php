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
     * @param bool $includeStyles Include inline CSS output
     * @param bool $wrapDocument Wrap output in a full HTML document
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateHighlightFormatter $formatter = new TemplateHighlightFormatter(),
        private readonly bool $includeStyles = true,
        private readonly bool $wrapDocument = false,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(SugarException $exception): string
    {
        if (!$exception instanceof CompilationException) {
            $content = htmlspecialchars($exception->getRawMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $styleHtml = $this->includeStyles ? $this->styleBlock() : '';

            return $this->wrapDocument
                ? $this->wrapOutput($content, $exception->getRawMessage(), $styleHtml)
                : $content;
        }

        $messageHtml = htmlspecialchars($exception->getRawMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationHtml = $this->formatLocation($exception);
        $traceHtml = $this->formatTrace($exception);
        $styleHtml = $this->includeStyles ? $this->styleBlock() : '';
        $inlineStyleHtml = $this->wrapDocument ? '' : $styleHtml;

        $source = $this->loadSource($exception);
        if ($source === null || $source === '') {
            $content = $this->buildOutput($inlineStyleHtml, $messageHtml, $locationHtml, null, $traceHtml);

            return $this->wrapDocument
                ? $this->wrapOutput($content, $exception->getRawMessage(), $styleHtml)
                : $content;
        }

        $line = $exception->templateLine ?? 1;
        $column = $exception->templateColumn ?? 1;
        $highlight = $this->formatter->format($source, $line, $column);

        if ($highlight->lines === []) {
            $content = $this->buildOutput($inlineStyleHtml, $messageHtml, $locationHtml, null, $traceHtml);

            return $this->wrapDocument
                ? $this->wrapOutput($content, $exception->getMessage(), $styleHtml)
                : $content;
        }

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

        $content = $this->buildOutput($inlineStyleHtml, $messageHtml, $locationHtml, $templateHtml, $traceHtml);

        return $this->wrapDocument
            ? $this->wrapOutput($content, $exception->getRawMessage(), $styleHtml)
            : $content;
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
     * Format a stack trace for display.
     */
    private function formatTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        if ($trace === []) {
            return '';
        }

        $items = [];
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '[internal]';
            if (isset($frame['line'])) {
                $file .= ':' . $frame['line'];
            }

            $call = '';
            if (isset($frame['class'])) {
                $call .= $frame['class'] . ($frame['type'] ?? '');
            }

            $call .= $frame['function'] ?? 'unknown';
            $call .= '()';

            $items[] = sprintf(
                '<li><span class="sugar-exception-trace-file">%s</span> ' .
                '<span class="sugar-exception-trace-call">%s</span></li>',
                htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($call, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return '<details class="sugar-exception-trace">' .
            '<summary>Sugar stack trace</summary>' .
            '<ol class="sugar-exception-trace-list">' .
            implode("\n", $items) .
            '</ol>' .
            '</details>';
    }

    /**
     * Build the HTML output for the exception.
     */
    private function buildOutput(
        string $styleHtml,
        string $messageHtml,
        string $locationHtml,
        ?string $templateHtml,
        string $traceHtml,
    ): string {
        $templateSection = $templateHtml !== null
            ? '<pre class="sugar-exception-template">' . $templateHtml . '</pre>'
            : '';

        return sprintf(
            '%s' .
            '<div class="sugar-exception">' .
            '<div class="sugar-exception-brand">' .
            '<div class="sugar-exception-logo" aria-hidden="true"></div>' .
            '<div class="sugar-exception-title">Sugar</div>' .
            '<a class="sugar-exception-badge" href="https://josbeir.github.io/sugar/" ' .
            'target="_blank" rel="noopener noreferrer">Docs</a>' .
            '</div>' .
            '<div class="sugar-exception-message">%s</div>' .
            '<div class="sugar-exception-location">%s</div>' .
            '%s' .
            '%s' .
            '</div>',
            $styleHtml,
            $messageHtml,
            $locationHtml,
            $templateSection,
            $traceHtml,
        );
    }

    /**
     * Wrap the output in a full HTML document.
     */
    private function wrapOutput(string $content, string $title, string $styleHtml = ''): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<!doctype html>' .
            '<html lang="en">' .
            '<head>' .
            '<meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1">' .
            '<title>%s</title>' .
            '%s' .
            '</head>' .
            '<body>' .
            '%s' .
            '</body>' .
            '</html>',
            $escapedTitle,
            $styleHtml,
            $content,
        );
    }

    /**
     * Inline stylesheet for the exception output.
     */
    private function styleBlock(): string
    {
        $logo = 'data:image/svg+xml;utf8,' .
            '%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2064%2064%22%3E'
            . '%3Cpath%20d%3D%22M32%204%20L56%2018%20v28%20L32%2060%20L8%2046%20V18%20Z%22%20'
            . 'fill%3D%22%23F8F5F0%22%20stroke%3D%22%23D9D2C8%22%20stroke-width%3D%222%22/%3E'
            . '%3Cpath%20d%3D%22M32%204%20L56%2018%20L32%2032%20L8%2018%20Z%22%20'
            . 'fill%3D%22%23FFFFFF%22%20stroke%3D%22%23E5DDD2%22%20stroke-width%3D%221.5%22/%3E'
            . '%3Cpath%20d%3D%22M32%2032%20L56%2018%20v28%20L32%2060%20Z%22%20'
            . 'fill%3D%22%23EFE8DD%22%20stroke%3D%22%23D9D2C8%22%20stroke-width%3D%221.5%22/%3E'
            . '%3Cpath%20d%3D%22M32%2032%20L8%2018%20v28%20L32%2060%20Z%22%20'
            . 'fill%3D%22%23F3EEE6%22%20stroke%3D%22%23D9D2C8%22%20stroke-width%3D%221.5%22/%3E'
            . '%3C/svg%3E';

        $css = <<<CSS
:root {
    --sugar-bg: #0d111a;
    --sugar-card: #141b29;
    --sugar-text: #e7edf7;
    --sugar-muted: #9aa7bd;
    --sugar-accent: #7fc2ff;
    --sugar-error: #ff8f8f;
    --sugar-mint: #89d3ff;
    --sugar-border: #22304a;
    --sugar-code: #f2f6ff;
}

html, body {
    background: var(--sugar-bg);
    color: var(--sugar-text);
    margin: 2.5rem;
    box-sizing: border-box;
}

.sugar-exception {
    font-family: "Plus Jakarta Sans", "Segoe UI", sans-serif;
    color: var(--sugar-text);
    background: radial-gradient(75rem 37.5rem at 10% 10%, rgba(127, 194, 255, 0.16) 0%, transparent 60%),
        radial-gradient(56.25rem 31.25rem at 90% 20%, rgba(137, 211, 255, 0.12) 0%, transparent 55%),
        var(--sugar-bg);
    border: 0.0625rem solid var(--sugar-border);
    border-radius: 1.25rem;
    padding: 1.5rem;
    margin: 0 auto;
    max-width: 1200px;
    box-shadow: 0 1.125rem 2.8125rem rgba(12, 8, 10, 0.45);
    animation: sugar-fade-in 220ms ease-out;

    .sugar-exception-message {
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: 0.0125rem;
        margin-bottom: 0.5rem;
    }

    .sugar-exception-brand {
        display: flex;
        flex-direction: row;
        align-items: center;
        text-align: left;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .sugar-exception-logo {
        width: 2rem;
        height: 2rem;
        margin-bottom: 0;
        flex-shrink: 0;
        background-image: url('{$logo}');
        background-repeat: no-repeat;
        background-size: contain;
        background-position: center;
    }

    .sugar-exception-title {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: 0.025rem;
        text-transform: uppercase;
        flex: 1;
        width: 100%;
    }

    .sugar-exception-badge {
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        text-decoration: none;
        color: var(--sugar-text);
        border: 0.0625rem solid var(--sugar-border);
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
        background: rgba(20, 27, 41, 0.7);
        transition: border-color 120ms ease, color 120ms ease, background 120ms ease;

        &:hover,
        &:focus-visible {
            color: var(--sugar-accent);
            border-color: var(--sugar-accent);
            background: rgba(127, 194, 255, 0.12);
        }
    }

    .sugar-exception-location {
        font-size: 0.8125rem;
        color: var(--sugar-muted);
        margin-bottom: 1.125rem;
    }

    .sugar-exception-template {
        font-family: "IBM Plex Mono", ui-monospace, monospace;
        font-size: 0.8125rem;
        line-height: 1.6;
        background: var(--sugar-card);
        border: 0.0625rem solid var(--sugar-border);
        border-radius: 1rem;
        padding: 1rem 1.125rem;
        color: var(--sugar-code);
        overflow-x: auto;
        box-shadow: inset 0 0 0 0.0625rem rgba(255, 255, 255, 0.06);
    }

    .sugar-error-line {
        background: rgba(242, 139, 139, 0.14);
        display: inline-block;
        width: 100%;
        margin: 0;
        padding: 0;
        line-height: inherit;
        vertical-align: top;
    }

    .sugar-error-caret {
        color: var(--sugar-error);
        font-weight: 700;
        display: inline-block;
        width: 100%;
        margin: 0;
        padding: 0;
        line-height: inherit;
        vertical-align: top;
    }

    .sugar-exception-trace {
        margin-top: 1.125rem;
        border: 0.0625rem solid var(--sugar-border);
        border-radius: 0.875rem;
        background: #1c171a;
        padding: 0.5rem 0.75rem;

        summary {
            cursor: pointer;
            color: var(--sugar-accent);
            list-style: none;

            &::-webkit-details-marker {
                display: none;
            }

            &::after {
                content: "";
                float: right;
                width: 0;
                height: 0;
                margin-top: 0.375rem;
                border-left: 0.3125rem solid transparent;
                border-right: 0.3125rem solid transparent;
                border-bottom: 0.375rem solid var(--sugar-accent);
                transition: transform 120ms ease;
            }
        }

        &[open] summary::after {
            transform: rotate(180deg);
        }
    }

    .sugar-exception-trace-list {
        margin: 0.625rem 0 0.25rem;
        padding-left: 1.375rem;
        font-family: "IBM Plex Mono", ui-monospace, monospace;
        font-size: 0.75rem;

        li {
            margin: 0.8rem 0;
        }
    }

    .sugar-exception-trace-file {
        color: var(--sugar-muted);
    }
}

@media (max-width: 720px) {
    body,
    html {
        margin: 0;
    }

    .sugar-exception {
        padding: 1.25rem;
        height: 100vh;
        border-radius: 0;
        border: 0;

        .sugar-exception-template {
            padding: 0.75rem 0.875rem;
        }
    }
}

@keyframes sugar-fade-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
CSS;

        return '<style>' . $css . '</style>';
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
