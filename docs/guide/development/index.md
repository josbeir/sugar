---
title: Engine Configuration
description: Configure the engine, loaders, cache, and template context.
---

# Engine Configuration

Configure the engine once and keep the rest of your templates clean. Most teams only need a custom prefix, loader paths, and a cache directory.

::: tip
Build your engine from a single `SugarConfig` instance so loaders, parser, and compiler share the same configuration.
:::

## Engine Configuration

Use the builder to configure loaders, cache, and debug mode in one place:

```php
use Sugar\Engine;
use Sugar\Cache\FileCache;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$config = SugarConfig::withPrefix('x');

$engine = Engine::builder($config)
    ->withTemplateLoader(new FileTemplateLoader(
        config: $config,
        templatePaths: [__DIR__ . '/templates'],
        componentPaths: ['components']
    ))
    ->withCache(new FileCache(__DIR__ . '/cache/templates'))
    ->withDebug(true)
    ->build();
```

::: info
`withDebug(true)` enables file timestamp checks for development. Disable it in production for best performance.
:::

## Custom Directive Prefix

Swap the `s:` prefix if you need to avoid collisions with another templating system:

```php
use Sugar\Config\SugarConfig;

$config = SugarConfig::withPrefix('v');
```

::: tip
After changing the prefix, use it consistently in templates and component tags.
:::

## Custom Directive Registry

Register only the directives you want to allow in a given environment:

```php
use Sugar\Extension\DirectiveRegistry;
use Sugar\Directive\IfDirective;
use Sugar\Directive\ForeachDirective;
use Sugar\Directive\CustomDirective;

$registry = DirectiveRegistry::empty();
$registry->register('if', IfDirective::class);
$registry->register('foreach', ForeachDirective::class);
$registry->register('custom', CustomDirective::class);
```

::: details
When to customize the directive registry

- Reduce surface area in locked-down environments
- Add project-specific directives
- Provide feature flags by swapping registries
:::

## Template Loaders

Template loaders decide how Sugar resolves templates and components. Use file-based loaders for production and string-based loaders for tests or dynamic templates.

::: tip
Keep template paths and component paths in one place so your engine configuration stays predictable across environments.
:::

### FileTemplateLoader

Use the filesystem for template resolution. `templatePaths` can be a single path or a list of paths.

::: code-group
```php
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: __DIR__ . '/templates',
    componentPaths: 'components'
);
```

```php [Multiple paths]
$loader = new FileTemplateLoader(
    config: new SugarConfig(),
    templatePaths: [
        __DIR__ . '/templates',
        __DIR__ . '/vendor/package/templates',
    ],
    componentPaths: 'components'
);
```
:::

### StringTemplateLoader

Use in-memory templates for tests, demos, or dynamic content.

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

::: details
When to use each loader

- `FileTemplateLoader` for real applications and caching
- `StringTemplateLoader` for tests, previews, or isolated render calls
:::

## Caching

Sugar includes a file-based cache with dependency tracking and cascade invalidation.

::: info
Caching compiles templates once and reuses the generated PHP for fast renders.
:::

### FileCache with Dependency Tracking

Use the default file cache for most applications:

```php
use Sugar\Engine;
use Sugar\Cache\FileCache;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Config\SugarConfig;

$cache = new FileCache(__DIR__ . '/cache/templates');

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        config: new SugarConfig(),
        templatePaths: __DIR__ . '/templates'
    ))
    ->withCache($cache)
    ->build();
```

::: tip
Place the cache directory outside your templates folder and ensure it is writable by PHP.
:::

### Debug vs Production

Debug mode checks file timestamps on every render. Production mode assumes cached templates are fresh.

::: code-group
```php [Debug]
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

::: warning
Disable debug mode in production to avoid repeated filesystem checks.
:::

## Debug Mode

Debug mode makes compiled output easier to trace back to the original template source. It is designed for development and should be disabled in production.

::: tip
Turn on debug mode when you are inspecting compiled templates or troubleshooting template output.
:::

### Enable Debug Mode

Enable debug mode to add source location comments to compiled templates:

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withDebug(true)
    ->build();
```

### What You Get

With debug mode enabled, compiled templates include source location comments that help you map back to the original file and line number.

::: code-group
```php [Compiled]
<?php /* sugar: templates/pages/home.sugar.php:12 */ ?>
<h1><?= \Sugar\Escape\Escaper::html($title) ?></h1>
```

```html [Template]
<h1><?= $title ?></h1>
```
:::

::: warning
Disable debug mode in production to avoid extra filesystem checks and template metadata in output.
:::

## Template Context

Template context lets you expose helper methods to every template via `$this`.

::: tip
Treat the context as a lightweight helper object. Keep it stateless when possible.
:::

```php
use Sugar\Engine;

$viewContext = new class {
    public function url(string $path): string
    {
        return '/app' . $path;
    }
};

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withTemplateContext($viewContext)
    ->build();
```

In templates:
```html
<a href="<?= $this->url('/profile') ?>">Profile</a>
```

### Common Patterns

::: code-group
```php [Helpers]
$viewContext = new class {
    public function url(string $path): string
    {
        return '/app' . $path;
    }

    public function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
};
```

```html [Template]
<link rel="stylesheet" href="<?= $this->asset('app.css') ?>">
```
:::

::: details
When to use template context

- Shared helpers like URL or asset builders
- Formatting helpers (dates, numbers) reused across templates
- Framework integration points that should not be global functions
:::
