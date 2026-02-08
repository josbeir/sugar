---
title: Caching
description: File cache, dependency tracking, and debug vs production modes.
---

# Caching

Sugar includes a file-based cache with dependency tracking and cascade invalidation.

::: info
Caching compiles templates once and reuses the generated PHP for fast renders.
:::

## FileCache with Dependency Tracking

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

## Debug vs Production

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
