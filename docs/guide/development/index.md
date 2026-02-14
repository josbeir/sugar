---
title: Engine Configuration
description: Configure the engine, loaders, cache, and template context.
---

# Engine Configuration

Set up your engine once and focus on building templates. You need three things to get started: where templates live, a cache directory, and whether to reload during development. Beyond that, customize as needed.

::: tip
Build from a single `SugarConfig` instance so loaders, parser, and compiler stay in sync.
:::

## Quick Start: The Essentials

These three things get you started:

1. **A template loader** — where Sugar finds your `.sugar.php` files
2. **A cache directory** — speeds up renders by compiling once
3. **(Optional) Debug mode** — auto-reload templates during development

Here's the minimal setup:

```php
use Sugar\Engine;
use Sugar\Cache\FileCache;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        config: new SugarConfig(),
        templatePaths: __DIR__ . '/templates'
    ))
    ->withCache(new FileCache(__DIR__ . '/cache/templates'))
    ->withDebug(false) // true during development
    ->build();

// Now render
echo $engine->render('home.sugar.php', ['title' => 'Welcome']);
```

That's it. Sugar compiles templates to pure PHP and caches them for you.

## All Builder Methods at a Glance

Here's every `with*` method for configuring the engine. Jump to any section for details:

| Method | Purpose | See |
| --- | --- | --- |
| `withTemplateLoader()` | Where templates are loaded from | [Template Loaders](#template-loaders) |
| `withCache()` | File-based compiled-template caching | [Caching](#caching) |
| `withDebug()` | Development checks and auto-reload | [Caching > Development vs Production](#development-vs-production) |
| `withPhpSyntaxValidation()` | Validate PHP syntax at compile time | [PHP Syntax Validation](#php-syntax-validation) |
| `withTemplateContext()` | Helpers available as `$this` in templates | [Template Context](#template-context) |
| `withExceptionRenderer()` | Custom exception rendering | [Exception Rendering](#exception-rendering) |
| `withHtmlExceptionRenderer()` | Built-in HTML exception renderer | [Exception Rendering](#exception-rendering) |
| `withFragmentCache()` | Enable `s:cache` with a PSR-16 store | [Fragment Caching (s:cache)](#fragment-caching-scache) |
| `withDirectiveRegistry()` | Override available directives | [Custom Directive Registry](#custom-directive-registry) |
| `withExtension()` | Register reusable directive/pass bundles | [Extensions](#extensions) |

## Configuration Options & Reference

Customize behavior with these builder methods. Click any section to learn more.

### Template Loaders

Tell Sugar where to find templates and components.

#### FileTemplateLoader

The standard choice: load templates from the filesystem.

```php
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: __DIR__ . '/templates',
    componentPaths: 'components'
);
```

Multiple paths? Pass an array:

```php
$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: [
        __DIR__ . '/templates',
        __DIR__ . '/vendor/package/templates',
    ],
    componentPaths: 'components'
);
```

::: tip
Enable `absolutePathsOnly: true` to enforce root-relative paths and prevent `../` navigation:

```php
$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: __DIR__ . '/templates',
    componentPaths: 'components',
    absolutePathsOnly: true
);
```
:::

#### StringTemplateLoader

Load templates from memory. Perfect for tests or dynamic content:

```php
use Sugar\Loader\StringTemplateLoader;
use Sugar\Config\SugarConfig;

$loader = new StringTemplateLoader(
    config: new SugarConfig(),
    templates: [
        'email/welcome' => '<h1>Welcome <?= $name ?>!</h1>',
    ],
    components: [
        'button' => '<button class="btn"><?= $slot ?></button>',
    ]
);
```

### Caching

Compile templates once, cache them, render fast.

```php
use Sugar\Engine;
use Sugar\Cache\FileCache;

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

### Configuration: Prefix & Suffix

#### Directive Prefix

Sugar uses `s:` by default (`s:if`, `s:foreach`, etc.). If that conflicts with another system, change it:

```php
use Sugar\Config\SugarConfig;

$config = SugarConfig::withPrefix('v');
// Now use v:if, v:foreach, v:cache, etc.
```

#### Template File Suffix

Custom file extension for templates:

```php
$config = (new SugarConfig())
    ->withFileSuffix('.sugar.tpl');
```

#### Fragment Element Name

Override the wrapperless fragment tag (default `<s-template>`):

```php
$config = (new SugarConfig())
    ->withFragmentElement('s-fragment');
```

```html
<s-fragment s:if="$condition">...</s-fragment>
```

#### Self-Closing Tags

Sugar recognizes HTML void elements automatically. Add custom self-closing tags:

::: code-group
```php [Replace list]
use Sugar\Config\SugarConfig;

$config = (new SugarConfig())
    ->withSelfClosingTags(['meta', 'link', 'custom']);
```

```php [Add to defaults]
use Sugar\Config\SugarConfig;

$config = (new SugarConfig())
    ->withSelfClosingTags([
        ...SugarConfig::DEFAULT_SELF_CLOSING_TAGS,
        'custom',
    ]);
```
:::

### Template Context

Expose helper methods to every template via `$this`. Keep context lightweight and stateless.

```php
use Sugar\Engine;

$context = new class {
    public function url(string $path): string {
        return '/app' . $path;
    }

    public function asset(string $file): string {
        return '/assets/' . ltrim($file, '/');
    }
};

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withTemplateContext($context)
    ->build();
```

In templates:

```html
<link rel="stylesheet" href="<?= $this->asset('app.css') ?>">
<a href="<?= $this->url('/profile') ?>">Profile</a>
```

::: details
When to use template context

- URL builders and asset helpers shared across templates
- Formatting helpers (dates, numbers, currency)
- Framework integration points
- Anything stateless templates frequently call
:::

### Fragment Caching (s:cache)

Cache expensive fragments using a PSR-16 store (Redis, Memcached, etc.). Optional but powerful for dynamic pages.

```php
use Psr\SimpleCache\CacheInterface;
use Sugar\Engine;

$fragmentCache = getYourCacheStore();

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withFragmentCache($fragmentCache, ttl: 300)
    ->build();
```

In templates, wrap expensive content:

```html
<section s:cache="'homepage:hero'">
    <?= renderExpensiveHtml() ?>
</section>
```

Override TTL per fragment:

```html
<section s:cache="['key' => 'users:list', 'ttl' => 60]">
    <?= renderUserList() ?>
</section>
```

See [s:cache directive](/guide/language/directives/control-flow#scache) for full syntax.

### PHP Syntax Validation

Optionally validate PHP syntax during compilation for earlier error detection. Requires `nikic/php-parser`:

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDebug(true)
    ->withPhpSyntaxValidation(true)
    ->build();
```

Benefits:
- Catch PHP syntax errors at compile time
- Faster feedback loop during development
- Zero overhead when disabled

::: tip
Enable in development, disable in production.
:::

### Exception Rendering

<!-- @include: ./_partials/exception-renderer-preview.md -->

### Custom Directive Registry

Start from scratch and register only the directives you want:

```php
use Sugar\Extension\DirectiveRegistry;
use Sugar\Directive\IfDirective;
use Sugar\Directive\ForeachDirective;

$registry = DirectiveRegistry::empty();
$registry->register('if', IfDirective::class);
$registry->register('foreach', ForeachDirective::class);

// Now only s:if and s:foreach are available
```

Use cases:
- Sandboxed/untrusted templates (reduce attack surface)
- Feature flags (swap registries by config)
- Project-specific custom directives

For directive authoring, see [Custom Directives](/guide/development/custom-directives).

### Extensions

Bundle directives, compiler passes, and hooks into reusable packages:

```php
use Sugar\Engine;

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AnalyticsExtension())
    ->withExtension(new UiComponentLibrary())
    ->build();
```

See [Creating Extensions](/guide/development/creating-extensions) for the full workflow.

## Builder Method Quick Lookup

| Method | Purpose |
| --- | --- |
| `withTemplateLoader()` | Where templates are loaded from |
| `withCache()` | File-based compiled-template caching |
| `withDebug()` | Development checks and auto-reload |
| `withPhpSyntaxValidation()` | Validate PHP syntax at compile time |
| `withTemplateContext()` | Helpers available as `$this` in templates |
| `withExceptionRenderer()` | Custom exception rendering |
| `withHtmlExceptionRenderer()` | Built-in HTML exception renderer |
| `withFragmentCache()` | Enable `s:cache` with a PSR-16 store |
| `withDirectiveRegistry()` | Override available directives |
| `withExtension()` | Register reusable directive/pass bundles |

## Learning More

- [AST Reference](/guide/development/ast) — understand Sugar's internal template representation
- [Helper Reference](/guide/development/helpers) — utilities for custom compiler passes
- [Custom Directives](/guide/development/custom-directives) — build your own `s:*` directives
- [Creating Extensions](/guide/development/creating-extensions) — package reusable features
