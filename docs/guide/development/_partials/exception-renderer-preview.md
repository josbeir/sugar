Sugar ships with a custom HTML exception renderer that produces a polished, themed error view. It is optional and only used when you register it via `withExceptionRenderer()`. It highlights the failing template lines, shows line/column context, and includes the message, location, and stack trace in a readable layout.

![Exception renderer preview](/exception_renderer.png)

The renderer uses the template loader to fetch the source, so pass the same loader you use for compilation.

```php
use Sugar\Engine;
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$config = new SugarConfig();
$loader = new FileTemplateLoader(
	config: $config,
	templatePaths: [__DIR__ . '/templates'],
);

$engine = Engine::builder($config)
	->withTemplateLoader($loader)
	->withExceptionRenderer(new HtmlTemplateExceptionRenderer($loader))
	->withDebug(true)
	->build();
```

You can also control how the renderer outputs its HTML:

```php
use Sugar\Exception\Renderer\HtmlTemplateExceptionRenderer;

$renderer = new HtmlTemplateExceptionRenderer(
	loader: $loader,
	includeStyles: true,
	wrapDocument: false,
);
```

- `includeStyles`: Toggle the inline CSS theme. Set to `false` if you want to provide your own styles.
- `wrapDocument`: Wrap the output in a full HTML document (`<!doctype html>`, `html`, `body`). Useful when you return the renderer output directly as a response body.

::: tip
Exception rendering only applies when debug mode is enabled and a `CompilationException` is thrown during rendering. In production, disable debug mode and handle exceptions with standard error pages.
:::
