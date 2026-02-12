<?php
declare(strict_types=1);

namespace Sugar\Exception\Renderer;

use ReflectionClass;
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
     * @param int $traceMaxFrames Maximum number of stack frames to render (0 = unlimited)
     * @param bool $traceIncludeArguments Include function arguments in stack trace
     * @param int $traceArgumentMaxLength Maximum string length per argument value
     * @param bool $traceIncludeInternalFrames Include frames without file/line metadata
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly TemplateHighlightFormatter $formatter = new TemplateHighlightFormatter(),
        private readonly bool $includeStyles = true,
        private readonly bool $wrapDocument = false,
        private readonly int $traceMaxFrames = 20,
        private readonly bool $traceIncludeArguments = false,
        private readonly int $traceArgumentMaxLength = 80,
        private readonly bool $traceIncludeInternalFrames = false,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(SugarException $exception): string
    {
        if (!$exception instanceof CompilationException) {
            $content = htmlspecialchars($exception->getRawMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $typeHtml = $this->formatType($exception);
            $styleHtml = $this->includeStyles ? $this->styleBlock() : '';
            $inlineStyleHtml = $this->wrapDocument ? '' : $styleHtml;

            $output = $this->buildOutput($inlineStyleHtml, $content, '', null, '', $typeHtml);

            return $this->wrapDocument
                ? $this->wrapOutput($output, $exception->getRawMessage(), $styleHtml)
                : $output;
        }

        $messageHtml = htmlspecialchars($exception->getRawMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationHtml = $this->formatLocation($exception);
        $typeHtml = $this->formatType($exception);
        $traceHtml = $this->formatTrace($exception);
        $styleHtml = $this->includeStyles ? $this->styleBlock() : '';
        $inlineStyleHtml = $this->wrapDocument ? '' : $styleHtml;

        $source = $this->loadSource($exception);
        if ($source === null || $source === '') {
            $content = $this->buildOutput($inlineStyleHtml, $messageHtml, $locationHtml, null, $traceHtml, $typeHtml);

            return $this->wrapDocument
                ? $this->wrapOutput($content, $exception->getRawMessage(), $styleHtml)
                : $content;
        }

        $line = $exception->templateLine ?? 1;
        $column = $exception->templateColumn ?? 1;
        $highlight = $this->formatter->format($source, $line, $column);

        if ($highlight->lines === []) {
            $content = $this->buildOutput($inlineStyleHtml, $messageHtml, $locationHtml, null, $traceHtml, $typeHtml);

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

        $content = $this->buildOutput(
            $inlineStyleHtml,
            $messageHtml,
            $locationHtml,
            $templateHtml,
            $traceHtml,
            $typeHtml,
        );

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
    public function formatTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        if ($trace === []) {
            return '';
        }

        $frames = [];
        foreach ($trace as $frame) {
            if (!$this->traceIncludeInternalFrames && !isset($frame['file'])) {
                continue;
            }

            $frames[] = $frame;
        }

        if ($frames === []) {
            return '';
        }

        $collapsedFrames = $this->collapseRepeatedFrames($frames);

        $truncated = false;
        if ($this->traceMaxFrames > 0 && count($collapsedFrames) > $this->traceMaxFrames) {
            $collapsedFrames = array_slice($collapsedFrames, 0, $this->traceMaxFrames);
            $truncated = true;
        }

        $items = [];
        foreach ($collapsedFrames as $index => $entry) {
            $frame = $entry['frame'];
            $file = $this->frameValueAsString($frame, 'file', '[internal]');
            $line = $this->frameValueAsString($frame, 'line');
            if ($line !== '') {
                $file .= ':' . $line;
            }

            $call = '';
            $class = $this->frameValueAsString($frame, 'class');
            if ($class !== '') {
                $call .= $class . $this->frameValueAsString($frame, 'type');
            }

            $call .= $this->frameValueAsString($frame, 'function', 'unknown');
            if ($this->traceIncludeArguments) {
                if (array_key_exists('args', $frame) && is_array($frame['args'])) {
                    $call .= '(' . $this->formatArguments($frame['args']) . ')';
                } else {
                    $call .= '(...)';
                }
            } else {
                $call .= '()';
            }

            if ($entry['count'] > 1) {
                $call .= sprintf(' (repeated %d times)', $entry['count']);
            }

            $items[] = sprintf(
                '<li><span class="sugar-exception-trace-index">#%d</span> ' .
                '<span class="sugar-exception-trace-file">%s</span> ' .
                '<span class="sugar-exception-trace-call">%s</span></li>',
                $index,
                htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($call, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        if ($truncated) {
            $items[] = '<li><span class="sugar-exception-trace-file">… trace truncated</span></li>';
        }

        return '<details class="sugar-exception-trace">' .
            '<summary>Sugar stack trace</summary>' .
            '<ol class="sugar-exception-trace-list">' .
            implode("\n", $items) .
            '</ol>' .
            '</details>';
    }

    /**
     * Collapse consecutive identical trace frames to reduce visual noise.
     *
     * @param array<array<string, mixed>> $frames
     * @return array<array{frame: array<string, mixed>, count: int}>
     */
    private function collapseRepeatedFrames(array $frames): array
    {
        /** @var array<array{signature: string, frame: array<string, mixed>, count: int}> $collapsed */
        $collapsed = [];

        foreach ($frames as $frame) {
            $signature = $this->frameSignature($frame);
            $lastIndex = count($collapsed) - 1;

            if ($lastIndex >= 0 && $collapsed[$lastIndex]['signature'] === $signature) {
                $collapsed[$lastIndex]['count']++;
                continue;
            }

            $collapsed[] = [
                'signature' => $signature,
                'frame' => $frame,
                'count' => 1,
            ];
        }

        $result = [];
        foreach ($collapsed as $entry) {
            $result[] = [
                'frame' => $entry['frame'],
                'count' => $entry['count'],
            ];
        }

        return $result;
    }

    /**
     * Build a stable signature for a stack frame.
     *
     * @param array<string, mixed> $frame
     */
    private function frameSignature(array $frame): string
    {
        return implode('|', [
            $this->frameValueAsString($frame, 'file'),
            $this->frameValueAsString($frame, 'line'),
            $this->frameValueAsString($frame, 'class'),
            $this->frameValueAsString($frame, 'type'),
            $this->frameValueAsString($frame, 'function'),
        ]);
    }

    /**
     * Convert a trace frame value to string safely.
     *
     * @param array<string, mixed> $frame
     */
    private function frameValueAsString(array $frame, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $frame)) {
            return $default;
        }

        $value = $frame[$key];
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }

        return $default;
    }

    /**
     * @param array<mixed> $args
     */
    private function formatArguments(array $args): string
    {
        if ($args === []) {
            return '';
        }

        $formatted = [];
        foreach ($args as $arg) {
            $formatted[] = $this->formatArgument($arg);
        }

        return implode(', ', $formatted);
    }

    /**
     * Format a single trace argument for display.
     */
    private function formatArgument(mixed $arg): string
    {
        if ($arg === null) {
            return 'null';
        }

        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }

        if (is_int($arg) || is_float($arg)) {
            return (string)$arg;
        }

        if (is_string($arg)) {
            return "'" . $this->truncate($arg, $this->traceArgumentMaxLength) . "'";
        }

        if (is_array($arg)) {
            return 'array(' . count($arg) . ')';
        }

        if (is_object($arg)) {
            return 'object(' . $arg::class . ')';
        }

        if (is_resource($arg)) {
            return 'resource(' . get_resource_type($arg) . ')';
        }

        return get_debug_type($arg);
    }

    /**
     * Truncate a string for safe stack trace argument rendering.
     */
    private function truncate(string $value, int $maxLength): string
    {
        $maxLength = max(1, $maxLength);
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        if ($maxLength === 1) {
            return '…';
        }

        return substr($value, 0, $maxLength - 1) . '…';
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
        string $typeHtml,
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
            '<div class="sugar-exception-type">%s</div>' .
            '<div class="sugar-exception-message">%s</div>' .
            '<div class="sugar-exception-location">%s</div>' .
            '%s' .
            '%s' .
            '</div>',
            $styleHtml,
            $typeHtml,
            $messageHtml,
            $locationHtml,
            $templateSection,
            $traceHtml,
        );
    }

    /**
     * Format an exception type label for display.
     */
    private function formatType(Throwable $exception): string
    {
        $short = (new ReflectionClass($exception))->getShortName();

        return htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    .sugar-exception-type {
        display: inline-flex;
        align-items: center;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--sugar-mint);
        border: 0.0625rem solid rgba(137, 211, 255, 0.35);
        border-radius: 999px;
        padding: 0.25rem 0.6rem;
        margin-bottom: 0.75rem;
        background: rgba(137, 211, 255, 0.08);
        width: fit-content;
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
