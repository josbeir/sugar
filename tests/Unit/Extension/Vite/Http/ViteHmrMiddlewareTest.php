<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Vite\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sugar\Extension\Vite\Http\ViteHmrMiddleware;

/**
 * Tests ViteHmrMiddleware development HTML injection behavior.
 */
final class ViteHmrMiddlewareTest extends TestCase
{
    /**
     * Verify middleware injects vite client and bridge scripts before head close.
     */
    public function testInjectsScriptsIntoHtmlHead(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ViteHmrMiddleware(
            streamFactory: $factory,
            enabled: true,
            devServerUrl: 'http://localhost:5173',
        );

        $request = new ServerRequest('GET', '/');
        $response = new Response(
            status: 200,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: '<html><head><title>Test</title></head><body>Hi</body></html>',
        );

        $result = $middleware->process($request, $this->createHandler($response));
        $content = (string)$result->getBody();

        $this->assertStringContainsString('@vite/client', $content);
        $this->assertStringContainsString('data-sugar-vite-hmr="1"', $content);
        $this->assertStringContainsString('virtual:sugar-vite-bridge', $content);

        $headClosePos = strpos($content, '</head>');
        $clientPos = strpos($content, '@vite/client');
        $this->assertIsInt($headClosePos);
        $this->assertIsInt($clientPos);
        $this->assertLessThan($headClosePos, $clientPos);
    }

    /**
     * Verify middleware does not inject scripts into non-HTML responses.
     */
    public function testSkipsNonHtmlResponses(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ViteHmrMiddleware(streamFactory: $factory);

        $request = new ServerRequest('GET', '/api');
        $response = new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{"ok":true}',
        );

        $result = $middleware->process($request, $this->createHandler($response));

        $this->assertSame('{"ok":true}', (string)$result->getBody());
        $this->assertStringNotContainsString('@vite/client', (string)$result->getBody());
    }

    /**
     * Verify middleware skips injection when disabled.
     */
    public function testSkipsInjectionWhenDisabled(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ViteHmrMiddleware(
            streamFactory: $factory,
            enabled: false,
        );

        $request = new ServerRequest('GET', '/');
        $response = new Response(
            status: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><head></head><body>Hello</body></html>',
        );

        $result = $middleware->process($request, $this->createHandler($response));

        $this->assertSame('<html><head></head><body>Hello</body></html>', (string)$result->getBody());
    }

    /**
     * Verify middleware does not duplicate scripts when marker already exists.
     */
    public function testSkipsWhenMarkerAlreadyExists(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ViteHmrMiddleware(streamFactory: $factory);

        $request = new ServerRequest('GET', '/');
        $response = new Response(
            status: 200,
            headers: ['Content-Type' => 'text/html'],
            body: '<html><head><script type="module" data-sugar-vite-hmr="1"></script></head><body>Hello</body></html>',
        );

        $result = $middleware->process($request, $this->createHandler($response));
        $content = (string)$result->getBody();

        $this->assertSame(1, substr_count($content, 'data-sugar-vite-hmr="1"'));
    }

    /**
     * Create request handler test double returning a fixed response.
     */
    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
