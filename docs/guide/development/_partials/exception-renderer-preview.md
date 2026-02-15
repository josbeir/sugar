Sugar ships with a custom HTML exception renderer that produces a polished, themed error view. It is optional and can be enabled with `withHtmlExceptionRenderer()` or by manually calling `withExceptionRenderer()`. It highlights the failing template lines, shows line/column context, and includes the message, location, and stack trace in a readable layout.

![Exception renderer preview](/exception_renderer.png)

The renderer uses the template loader to fetch the source.

Consecutive identical stack frames are automatically collapsed in the rendered trace to reduce recursion noise.

```php
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Core\Loader\FileTemplateLoader;

$config = new SugarConfig();
$loader = new FileTemplateLoader(
	config: $config,
	templatePaths: [__DIR__ . '/templates'],
);

$engine = Engine::builder($config)
	->withTemplateLoader($loader)
	->withHtmlExceptionRenderer(includeStyles: true, wrapDocument: false)
	->withDebug(true)
	->build();
```

`withHtmlExceptionRenderer()` accepts the same rendering toggles as the renderer constructor:

- `includeStyles`: Include inline CSS in the output.
- `wrapDocument`: Wrap the output in a full HTML document.

Use `withExceptionRenderer()` when you need custom renderer options:

```php
use Sugar\Core\Exception\Renderer\HtmlTemplateExceptionRenderer;

$renderer = new HtmlTemplateExceptionRenderer(
	loader: $loader,
	includeStyles: true,
	wrapDocument: false,
	traceMaxFrames: 20,
	traceIncludeArguments: false,
	traceArgumentMaxLength: 80,
	traceIncludeInternalFrames: false,
);
```

- `includeStyles`: Toggle the inline CSS theme. Set to `false` if you want to provide your own styles.
- `wrapDocument`: Wrap the output in a full HTML document (`<!doctype html>`, `html`, `body`). Useful when you return the renderer output directly as a response body.
- `traceMaxFrames`: Maximum number of stack frames shown (`0` means unlimited).
- `traceIncludeArguments`: Include function arguments in each trace frame.
- `traceArgumentMaxLength`: Max string length per rendered argument.
- `traceIncludeInternalFrames`: Include frames without file/line metadata.

::: tip
Exception rendering only applies when debug mode is enabled and a `CompilationException` is thrown during rendering. In production, disable debug mode and handle exceptions with standard error pages.
:::
