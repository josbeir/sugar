# Vite Runtime Integration

Sugar ships a backend-first Vite integration through `ViteExtension` and an optional frontend Vite plugin.

## Backend: `ViteExtension`

Use `s:vite` in templates and configure runtime behavior in PHP:

```php
use Sugar\Core\Engine;
use Sugar\Extension\Vite\ViteExtension;

$engine = Engine::builder()
    ->withExtension(new ViteExtension(
        mode: 'prod',
        manifestPath: '/var/www/app/webroot/build/manifest.json',
        assetBaseUrl: '/build',
    ))
    ->build();
```

### Important Options

- `mode`: `auto`, `dev`, `prod`
- `manifestPath`: filesystem path to `manifest.json` (production)
- `assetBaseUrl`: explicit public URL prefix for emitted assets (recommended)
- `buildBaseUrl`: fallback base used when `assetBaseUrl` is not set
- `devServerUrl`: Vite dev server origin
- `injectClient`: include `@vite/client` automatically in dev mode

## Directive Usage

```sugar
<s-template s:vite="resources/assets/js/site.js" />
```

You can also use it on regular elements without preserving the wrapper:

```sugar
<link s:vite="resources/assets/css/app.css" />
```

## Frontend: optional Vite plugin

Sugar includes a Vite plugin module at:

- `src/Extension/Vite/Plugin/sugarVitePlugin.mjs`

It broadcasts `sugar:partial-update` events when template files change and provides a client bridge module.

By default, the bridge performs a partial update by fetching the current page HTML and replacing a configurable root target (`updateTargetSelector`).
If partial update fails, it falls back to a full page reload.
The bridge fetch uses cache-busting (`__sugar_hmr`) and `no-store` to avoid stale/conditional responses during rapid edits.
When fallback happens, the bridge logs a warning in the browser console and stores the reason in `window.__sugarLastHmrError`.

```ts
import { defineConfig } from 'vite';
import { sugarVitePlugin } from '../src/Extension/Vite/Plugin/sugarVitePlugin.mjs';

export default defineConfig({
  plugins: [
    sugarVitePlugin({
      templateExtensions: ['.sugar.php', '.php'],
      injectClientBridge: true,
      reloadOnFailure: true,
      updateTargetSelector: '[data-sugar-hmr-root], #app, main',
      morphStrategy: 'auto',
    }),
  ],
});
```

Set `reloadOnFailure: false` temporarily while debugging partial updates. The bridge stores the latest error in `window.__sugarLastHmrError` and also persists it across reloads.
Set `updateTargetSelector` to a stable application root to avoid replacing runtime-managed elements (such as Vite overlay internals).
Set `morphStrategy` to control patching behavior: `auto` (default), `none`, `alpine`, or `idiomorph`.

For `morphStrategy: 'alpine'` (and `auto`), the bridge attempts to load `alpinejs` and `@alpinejs/morph` through Vite's `/@id/...` module URLs automatically.
For `morphStrategy: 'idiomorph'` (and `auto` when Alpine Morph is unavailable), the bridge attempts to load `idiomorph` via `/@id/idiomorph`.
Install the corresponding package(s) in your application for the selected strategy.

If you use `ViteHmrMiddleware` with server-rendered pages, it automatically imports `/@id/virtual:sugar-vite-bridge` from your configured `devServerUrl`, so no manual `import('virtual:sugar-vite-bridge')` is required in userland code.

Example root markup:

```html
<main data-sugar-hmr-root>
  ...
</main>
```

## Recommendation

Set `assetBaseUrl` in production to avoid any URL base ambiguity.
