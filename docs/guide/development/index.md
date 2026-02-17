---
title: Engine Configuration
description: Configure the engine, loaders, cache, and template context.
---

# Engine Configuration

Set up your engine once and focus on building templates. You need three things to get started: where templates live, a cache directory, and whether to reload during development.

::: tip
Build from a single `SugarConfig` instance so parser/compiler behavior stays in sync.
:::

## Quick Start

These three things get you running:

1. **A template loader** — where Sugar finds your `.sugar.php` files
2. **A cache directory** — speeds up renders by compiling once
3. **Debug mode** — auto-reload templates during development

Here's the minimal setup:

```php
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        templatePaths: __DIR__ . '/templates'
    ))
    ->withCache(new FileCache(__DIR__ . '/cache/templates'))
    ->withDebug(true) // Enable during development
    ->build();

// Now render
echo $engine->render('home', ['title' => 'Welcome']);
```

That's it. Sugar compiles templates to pure PHP and caches them for you.

## Core Configuration

### Template Loaders

Tell Sugar where to find templates.

#### FileTemplateLoader

The standard choice: load templates from the filesystem.

```php
use Sugar\Core\Loader\FileTemplateLoader;

$loader = new FileTemplateLoader(
    templatePaths: __DIR__ . '/templates'
);
```

**Multiple paths:**

```php
$loader = new FileTemplateLoader(
    templatePaths: [
        __DIR__ . '/templates',
        __DIR__ . '/vendor/package/templates',
    ],
);
```

**Enforce absolute paths:**

```php
$loader = new FileTemplateLoader(
    templatePaths: __DIR__ . '/templates',
    absolutePathsOnly: true // Prevents ../ navigation
);
```

::: tip
Enable `absolutePathsOnly: true` to enforce root-relative paths and prevent `../` navigation for includes and extends.
:::

#### StringTemplateLoader

Load templates from memory. Perfect for tests or dynamic content:

```php
use Sugar\Core\Loader\StringTemplateLoader;

$loader = new StringTemplateLoader(
    templates: [
        'email/welcome' => '<h1>Welcome <?= $name ?>!</h1>',
        'components/s-button.sugar.php' => '<button class="btn"><?= $slot ?></button>',
    ]
);
```

### Caching

Sugar compiles templates to pure PHP once and caches the result. Subsequent renders execute the cached code directly via `include`, leveraging PHP's opcache for maximum performance. No re-compilation happens until the source template changes (in debug mode) or the cache is cleared.

::: tip
See [File Caching Flow](/guide/reference/architecture#file-caching-flow) for details on how Sugar's compilation and caching pipeline works.
:::

Compile templates once, cache them, render fast.

```php
use Sugar\Core\Cache\FileCache;

$cache = new FileCache(__DIR__ . '/cache/templates');

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withCache($cache)
    ->build();
```

::: warning
Place cache outside your templates folder and ensure it's writable.
:::

#### Development vs Production

**Development** (debug mode): Check file timestamps on every render so you see template changes immediately.

**Production** (no debug): Trust the cache — no filesystem checks, maximum speed.

::: code-group
```php [Development]
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withCache($cache)
    ->withDebug(true)
    ->build();
```

```php [Production]
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withCache($cache)
    ->withDebug(false)
    ->build();
```
:::

## Customization

`SugarConfig` controls engine internals like directive prefixes, fragment element names, and parser behavior. Use it to adjust Sugar's syntax when the defaults don't fit your project or to avoid conflicts with other systems.

### Directive Prefix

Sugar uses `s:` by default (`s:if`, `s:foreach`, etc.). Change it if needed:

```php
use Sugar\Core\Config\SugarConfig;

$config = SugarConfig::withPrefix('v');
// Now use v:if, v:foreach, v:cache, etc.

$engine = Engine::builder()
    ->withSugarConfig($config)
    ->withTemplateLoader($loader)
    ->build();
```

### Template Suffixes

Configure file extensions on the loader:

::: code-group
```php [FileTemplateLoader]
use Sugar\Core\Loader\FileTemplateLoader;

$loader = new FileTemplateLoader(
    templatePaths: __DIR__ . '/templates',
    suffixes: ['.sugar.tpl'],
);
```

```php [StringTemplateLoader]
use Sugar\Core\Loader\StringTemplateLoader;

$loader = new StringTemplateLoader(
    templates: [
        'pages/home.sugar.tpl' => '<h1><?= $title ?></h1>',
    ],
    suffixes: ['.sugar.tpl'],
);
```
:::

### Fragment Element Name

Override the wrapperless fragment tag (default `<s-template>`):

```php
use Sugar\Core\Config\SugarConfig;

$config = (new SugarConfig())
    ->withFragmentElement('s-fragment');

$engine = Engine::builder()
    ->withSugarConfig($config)
    ->withTemplateLoader($loader)
    ->build();
```

Now use:

```sugar
<s-fragment s:if="$condition">...</s-fragment>
```

### Self-Closing Tags

Sugar recognizes HTML void elements automatically. Add custom self-closing tags:

::: code-group
```php [Replace list]
use Sugar\Core\Config\SugarConfig;

$config = (new SugarConfig())
    ->withSelfClosingTags(['meta', 'link', 'custom']);
```

```php [Add to defaults]
use Sugar\Core\Config\SugarConfig;

$config = (new SugarConfig())
    ->withSelfClosingTags([
        ...SugarConfig::DEFAULT_SELF_CLOSING_TAGS,
        'custom',
    ]);
```
:::

## Template Helpers

### Template Context

Expose helper methods to every template via `$this`. Keep context lightweight and stateless.

```php
use Sugar\Core\Engine;

$context = new class { // [!code focus]
    public function url(string $path): string { // [!code focus]
        return '/app' . $path; // [!code focus]
    } // [!code focus]
// [!code focus]
    public function asset(string $file): string { // [!code focus]
        return '/assets/' . ltrim($file, '/'); // [!code focus]
    } // [!code focus]
}; // [!code focus]

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withTemplateContext($context) // [!code focus]
    ->build();
```

In templates:

```html
<link rel="stylesheet" href="<?= $this->asset('app.css') ?>">
<a href="<?= $this->url('/profile') ?>">Profile</a>
```

::: tip When to use template context
- URL builders and asset helpers shared across templates
- Formatting helpers (dates, numbers, currency)
- Framework integration points
- Anything stateless templates frequently call
:::

## Advanced Features

### Fragment Caching (s:cache)

Cache expensive fragments using a PSR-16 store (Redis, Memcached, etc.) through the optional FragmentCache extension.

```php
use Sugar\Core\Engine;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;

$fragmentCache = getYourCacheStore(); // PSR-16 compatible  // [!code focus]

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new FragmentCacheExtension($fragmentCache, defaultTtl: 300)) // [!code focus]
    ->build();
```

In templates, wrap expensive content:

```sugar
<section s:cache="'homepage:hero'">
    <?= renderExpensiveHtml() ?>
</section>
```

Override TTL per fragment:

```sugar
<section s:cache="['key' => 'users:list', 'ttl' => 60]">
    <?= renderUserList() ?>
</section>
```

See [s:cache directive](/guide/language/directives/control-flow#s-cache) for full syntax.

### PHP Syntax Validation

Optionally validate PHP syntax during compilation for earlier error detection. Requires `nikic/php-parser`:

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDebug(true)
    ->withPhpSyntaxValidation(true)  // [!code focus]
    ->build();
```

**Benefits:**
- Catch PHP syntax errors at compile time
- Faster feedback loop during development
- Zero overhead when disabled

::: tip
Enable in development, disable in production.
:::

### Exception Rendering

<!-- @include: ./_partials/exception-renderer-preview.md -->

## Extending the Engine

### Extensions

Bundle directives, compiler passes, and hooks into reusable packages:

```php
use Sugar\Core\Engine;

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AnalyticsExtension()) // [!code focus]
    ->withExtension(new UiComponentLibrary()) // [!code focus]
    ->build();
```

See [Creating Extensions](/guide/development/creating-extensions) for the full workflow.

### Custom Directive Registry

For quick, one-off custom directives without creating a full extension. **Extensions are preferred** for reusable or complex directive bundles.

Add your custom directive to the existing registry:

```php
use Sugar\Core\Extension\DirectiveRegistry;

$registry = DirectiveRegistry::default(); // [!code focus]
$registry->register('custom', CustomDirective::class); // [!code focus]

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDirectiveRegistry($registry) // [!code focus]
    ->build();

// Now s:custom is available alongside built-in directives
```

**Use cases:**
- Quick prototypes or experiments
- Project-specific directives
- Adding one or two custom directives without extension overhead

::: tip
For reusable directive bundles, create an [Extension](/guide/development/creating-extensions) instead. Extensions provide better organization and can include multiple directives, compiler passes, and hooks.
:::

::: details Advanced: Empty Registry
Start from scratch and register only the directives you want. This removes all built-in directives:

```php
use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Extension\DirectiveRegistry;

$registry = DirectiveRegistry::empty();
$registry->register('if', IfDirective::class);
$registry->register('foreach', ForeachDirective::class);

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDirectiveRegistry($registry)
    ->build();

// Now only s:if and s:foreach are available
```

Useful for sandboxed/untrusted templates or feature flags.
:::

For directive authoring, see [Custom Directives](/guide/development/custom-directives).

## Complete Reference

All builder methods at a glance:

| Method | Purpose |
| --- | --- |
| `withTemplateLoader()` | Where templates are loaded from |
| `withCache()` | File-based compiled-template caching |
| `withDebug()` | Development checks and auto-reload |
| `withSugarConfig()` | Parser/compiler configuration (prefix, suffixes, etc.) |
| `withTemplateContext()` | Helpers available as `$this` in templates |
| `withPhpSyntaxValidation()` | Validate PHP syntax at compile time |
| `withExceptionRenderer()` | Custom exception rendering |
| `withHtmlExceptionRenderer()` | Built-in HTML exception renderer |
| `withExtension()` | Register reusable directive/pass bundles |
| `withDirectiveRegistry()` | Override available directives |

## Next Steps

- [AST Reference](/guide/development/ast) — understand Sugar's internal template representation
- [Helper Reference](/guide/development/helpers) — utilities for custom compiler passes
- [Custom Directives](/guide/development/custom-directives) — build your own `s:*` directives
- [Creating Extensions](/guide/development/creating-extensions) — package reusable features
