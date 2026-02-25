# Vite Extension

`ViteExtension` integrates Sugar templates with Vite asset tags.

Use it to render Vite entries directly from templates with `s:vite`, while keeping environment-specific behavior in PHP configuration. In development, it can emit dev-server tags (including `@vite/client` when enabled), and in production it resolves assets from your Vite manifest.

The extension is intentionally backend-oriented: you keep your normal Vite setup for bundling and dev server behavior, and Sugar focuses on resolving and outputting the correct tags at render time.

`assetBaseUrl` is required configuration for the extension.

## Register the extension

```php
use Sugar\Core\Engine;
use Sugar\Extension\Vite\ViteExtension;

$engine = Engine::builder()
    ->withExtension(new ViteExtension(
        mode: 'auto',
        manifestPath: '/var/www/app/webroot/build/manifest.json',
        assetBaseUrl: '/build/',
        devServerUrl: 'http://localhost:5173',
        injectClient: true,
    ))
    ->build();
```

## Using `s:vite`

Use the directive where you want Vite tags emitted:

```sugar
<s-template s:vite="resources/assets/js/site.js" />
```

You can also apply it on regular elements:

```sugar
<link s:vite="resources/assets/css/app.css" />
```

`ViteDirective` also supports custom-element syntax through element claiming:

```sugar
<s-vite src="resources/assets/js/site.js" />
```

In this form, `src` is used as the directive expression and produces the same output as `s:vite="..."`.

## Runtime modes

- `auto`: Uses development behavior in debug mode and production behavior otherwise.
- `dev`: Always emits dev server tags.
- `prod`: Always resolves assets from `manifest.json`.

## Configuration options

- `mode`: `auto`, `dev`, or `prod`.
- `manifestPath`: Absolute filesystem path to the Vite `manifest.json`.
- `assetBaseUrl`: Public URL prefix for built assets (required).
- `devServerUrl`: Vite development server origin.
- `injectClient`: Automatically inject `@vite/client` in development mode.
- `defaultEntry`: Entry used when `s:vite` is used as a boolean directive.

## Production recommendation

Set `manifestPath` and `assetBaseUrl` explicitly in production.
