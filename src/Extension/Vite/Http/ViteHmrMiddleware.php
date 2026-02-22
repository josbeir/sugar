<?php
declare(strict_types=1);

namespace Sugar\Extension\Vite\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sugar\Core\Escape\Escaper;

/**
 * PSR-15 middleware that injects Vite HMR scripts in development HTML responses.
 *
 * This middleware is framework-agnostic and intended for host applications using
 * Sugar templates with Vite in development mode.
 */
final readonly class ViteHmrMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory PSR-17 stream factory used to replace response body content
     * @param bool $enabled Whether middleware injection is enabled
     * @param string $devServerUrl Vite development server URL
     * @param bool $injectViteClient Whether to inject `@vite/client` module script
     * @param bool $injectBridgeRuntime Whether to inject Sugar partial-update bridge runtime
     * @param string|null $nonce Optional CSP nonce to apply on injected script tags
     */
    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private bool $enabled = true,
        private string $devServerUrl = 'http://localhost:5173',
        private bool $injectViteClient = true,
        private bool $injectBridgeRuntime = true,
        private ?string $nonce = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->enabled || !$this->isHtmlResponse($response)) {
            return $response;
        }

        $bodyContent = (string)$response->getBody();
        if ($bodyContent === '') {
            return $response;
        }

        if (str_contains($bodyContent, 'data-sugar-vite-hmr="1"')) {
            return $response;
        }

        $injection = $this->buildInjectionMarkup();
        if ($injection === '') {
            return $response;
        }

        $updatedBody = $this->injectMarkup($bodyContent, $injection);
        if ($updatedBody === $bodyContent) {
            return $response;
        }

        $updatedResponse = $response->withBody($this->streamFactory->createStream($updatedBody));

        if ($updatedResponse->hasHeader('Content-Length')) {
            return $updatedResponse->withoutHeader('Content-Length');
        }

        return $updatedResponse;
    }

    /**
     * Determine whether a response is HTML content.
     */
    private function isHtmlResponse(ResponseInterface $response): bool
    {
        if (!$response->hasHeader('Content-Type')) {
            return false;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        return str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
    }

    /**
     * Build combined script markup for injection.
     */
    private function buildInjectionMarkup(): string
    {
        $tags = [];
        $nonceAttribute = $this->buildNonceAttribute();

        if ($this->injectViteClient) {
            $clientUrl = rtrim($this->devServerUrl, '/') . '/@vite/client';
            $tags[] = sprintf(
                '<script type="module" src="%s"%s></script>',
                Escaper::attr($clientUrl),
                $nonceAttribute,
            );
        }

        if ($this->injectBridgeRuntime) {
            $bridgeModuleUrlLiteral = $this->buildBridgeModuleUrlLiteral();

            $bridgeRuntime = <<<JS
const sugarBridgeModuleUrl = {$bridgeModuleUrlLiteral};
import(sugarBridgeModuleUrl).catch(() => {});

window.addEventListener('sugar:partial-update', async (event) => {
    const handler = window.__sugarHmrHandlePartialUpdate;
    if (typeof handler === 'function') {
        await handler(event.detail ?? {});
        return;
    }

    window.location.reload();
});
JS;

            $tags[] = sprintf(
                '<script type="module" data-sugar-vite-hmr="1"%s>%s</script>',
                $nonceAttribute,
                $bridgeRuntime,
            );
        }

        return implode("\n", $tags);
    }

    /**
     * Build JavaScript string literal for the Sugar bridge virtual module URL.
     */
    private function buildBridgeModuleUrlLiteral(): string
    {
        $moduleUrl = rtrim($this->devServerUrl, '/') . '/@id/virtual:sugar-vite-bridge';
        $encoded = json_encode($moduleUrl);

        if (is_string($encoded)) {
            return $encoded;
        }

        return '"/@id/virtual:sugar-vite-bridge"';
    }

    /**
     * Build optional nonce attribute for script tags.
     */
    private function buildNonceAttribute(): string
    {
        if ($this->nonce === null || $this->nonce === '') {
            return '';
        }

        return ' nonce="' . Escaper::attr($this->nonce) . '"';
    }

    /**
     * Inject script markup into HTML, preferring placement before </head>.
     */
    private function injectMarkup(string $html, string $markup): string
    {
        $headClosePos = stripos($html, '</head>');
        if ($headClosePos !== false) {
            return substr($html, 0, $headClosePos)
                . $markup
                . "\n"
                . substr($html, $headClosePos);
        }

        return $markup . "\n" . $html;
    }
}
